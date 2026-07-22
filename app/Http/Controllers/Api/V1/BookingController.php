<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\BookingResource;
use App\Models\BlockedSlot;
use App\Models\Booking;
use App\Models\BookingStatus;
use App\Models\Location;
use App\Models\Service;
use App\Policies\BookingPolicy;
use App\Services\IdempotencyService;
use App\Services\SchedulingLockService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BookingController extends Controller
{
    public function __construct(
        private readonly BookingPolicy $bookingPolicy,
        private readonly IdempotencyService $idempotency,
        private readonly SchedulingLockService $schedulingLocks,
    ) {}

    // ── GET /v1/bookings ───────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $this->authorize('viewAny', Booking::class);

        $bookings = $this->bookingPolicy->scopeVisibleTo(
            Booking::with(['client', 'service', 'provider', 'location', 'status', 'sale', 'packSession.clientPack']),
            $user,
        )
            ->when($request->client_id, fn ($q) => $q->where('client_id', $request->client_id))
            ->when($request->service_id, fn ($q) => $q->where('service_id', $request->service_id))
            ->when($request->provider_id, fn ($q) => $q->where('provider_id', $request->provider_id))
            ->when($request->location_id, fn ($q) => $q->where('location_id', $request->location_id))
            ->when($request->status_id, fn ($q) => $q->where('status_id', $request->status_id))
            ->when($request->date_from, fn ($q) => $q->whereDate('start_time', '>=', $request->date_from))
            ->when($request->date_to, fn ($q) => $q->whereDate('start_time', '<=', $request->date_to))
            ->when($request->wc_order_id, fn ($q) => $q->where('wc_order_id', $request->wc_order_id))
            ->orderBy('start_time', 'desc')
            ->paginate($request->per_page ?? 15);

        return BookingResource::collection($bookings)->response();
    }

    // ── GET /v1/bookings/{id} ──────────────────────────────────────
    public function show(Request $request, int $id): JsonResponse
    {
        $booking = Booking::with(['client', 'service', 'provider', 'location', 'status', 'statusHistory.status', 'sale', 'packSession.clientPack'])
            ->findOrFail($id);

        $this->authorize('view', $booking);

        return response()->json(['data' => new BookingResource($booking)]);
    }

    // ── POST /v1/bookings ──────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $endpoint = 'POST /v1/bookings';
        $requestHash = md5($request->getContent());
        $hasIdempotencyKey = $request->hasHeader('Idempotency-Key');

        $validated = $request->validate([
            'start_time' => ['required', 'date', 'after:now'],
            'end_time' => ['nullable', 'date', 'after:start_time'],
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'provider_id' => ['nullable', 'integer', 'exists:providers,id'],
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'status_id' => ['required', 'integer', 'exists:booking_statuses,id'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'duration_minutes' => ['nullable', 'integer', 'min:15', 'max:480'],
            'wc_order_id' => ['nullable', 'integer'],
        ]);

        $service = Service::findOrFail($validated['service_id']);
        $location = Location::findOrFail($validated['location_id']);
        $this->authorize('create', [Booking::class, $location]);
        $startTime = Carbon::parse($validated['start_time']);

        // Duración efectiva — CHG-001
        $effectiveDuration = $validated['duration_minutes']
            ?? $service->duration_minutes
            ?? (int) env('BOOKING_DEFAULT_DURATION_MINUTES', 30);

        $endTime = isset($validated['end_time'])
            ? Carbon::parse($validated['end_time'])
            : $startTime->copy()->addMinutes($effectiveDuration);

        if ($hasIdempotencyKey) {
            $cached = $this->idempotency->check($request, $endpoint);
            if ($cached !== null) {
                return $cached;
            }

            $status = $this->idempotency->acquire($request, $endpoint, $requestHash);
            if ($status === 1) {
                return response()->json([
                    'error' => 'conflict',
                    'detail' => 'A request with this idempotency key is already in progress or conflicts.',
                ], 409);
            }
        }

        try {
            $result = DB::transaction(function () use ($validated, $startTime, $endTime, $service): array {
                $this->schedulingLocks->lock(
                    $validated['location_id'],
                    $validated['client_id'],
                    $validated['provider_id'] ?? null,
                );

                $conflict = $this->findSchedulingConflict(
                    $validated['location_id'],
                    $validated['client_id'],
                    $validated['provider_id'] ?? null,
                    $startTime,
                    $endTime,
                );

                if ($conflict !== null) {
                    return ['conflict' => $conflict];
                }

                return ['booking' => Booking::create([
                    ...$validated,
                    'end_time' => $endTime,
                    'custom_duration_minutes' => $validated['duration_minutes'] ?? null,
                    'price' => $validated['price'] ?? $service->price,
                ])];
            }, 3);
        } catch (\Throwable $exception) {
            if ($hasIdempotencyKey) {
                $this->idempotency->release($request, $endpoint);
            }

            throw $exception;
        }

        if (isset($result['conflict'])) {
            if ($hasIdempotencyKey) {
                $this->idempotency->release($request, $endpoint);
            }

            return $this->conflictResponse($result['conflict']);
        }

        $booking = $result['booking'];

        $booking->load(['client', 'service', 'provider', 'location', 'status']);

        if ($hasIdempotencyKey) {
            $this->idempotency->store($request, $endpoint, 201, ['data' => new BookingResource($booking)]);
        }

        return response()->json(['data' => new BookingResource($booking)], 201);
    }

    // ── PATCH /v1/bookings/{id} ────────────────────────────────────
    public function update(Request $request, int $id): JsonResponse
    {
        $booking = Booking::findOrFail($id);
        $this->authorize('update', $booking);

        $validated = $request->validate([
            'start_time' => ['sometimes', 'date', 'after:now'],
            'end_time' => ['sometimes', 'date', 'after:start_time'],
            'status_id' => ['sometimes', 'integer', 'exists:booking_statuses,id'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'provider_id' => ['sometimes', 'nullable', 'integer', 'exists:providers,id'],
        ]);

        $result = DB::transaction(function () use ($booking, $validated): array {
            $lockedBooking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $startTime = isset($validated['start_time']) ? Carbon::parse($validated['start_time']) : $lockedBooking->start_time;
            $endTime = isset($validated['end_time'])
                ? Carbon::parse($validated['end_time'])
                : ($lockedBooking->end_time ?? $startTime->copy()->addMinutes($lockedBooking->effective_duration_minutes));

            $this->schedulingLocks->lock(
                $lockedBooking->location_id,
                $lockedBooking->client_id,
                $validated['provider_id'] ?? $lockedBooking->provider_id,
            );

            if (isset($validated['start_time']) || isset($validated['end_time']) || isset($validated['provider_id'])) {
                $conflict = $this->findSchedulingConflict(
                    $lockedBooking->location_id,
                    $lockedBooking->client_id,
                    $validated['provider_id'] ?? $lockedBooking->provider_id,
                    $startTime,
                    $endTime,
                    $lockedBooking->id,
                );

                if ($conflict !== null) {
                    return ['conflict' => $conflict];
                }
            }

            if (isset($validated['start_time']) && ! isset($validated['end_time'])) {
                $validated['end_time'] = $endTime;
            }

            $lockedBooking->update($validated);

            return ['booking' => $lockedBooking];
        }, 3);

        if (isset($result['conflict'])) {
            return $this->conflictResponse($result['conflict']);
        }

        $booking = $result['booking'];
        $booking->load(['client', 'service', 'provider', 'location', 'status']);

        return response()->json(['data' => new BookingResource($booking)]);
    }

    // ── PATCH /v1/bookings/{id}/cancel ────────────────────────────
    public function cancel(Request $request, int $id): JsonResponse
    {
        $booking = Booking::findOrFail($id);
        $this->authorize('cancel', $booking);

        $endpoint = 'PATCH /v1/bookings/'.$id.'/cancel';
        $requestHash = md5($request->getContent());
        $hasIdempotencyKey = $request->hasHeader('Idempotency-Key');

        if ($hasIdempotencyKey) {
            $cached = $this->idempotency->check($request, $endpoint);
            if ($cached !== null) {
                return $cached;
            }

            $status = $this->idempotency->acquire($request, $endpoint, $requestHash);
            if ($status === 1) {
                return response()->json([
                    'error' => 'conflict',
                    'detail' => 'A request with this idempotency key is already in progress or conflicts.',
                ], 409);
            }
        }

        $cancelStatus = BookingStatus::where('is_cancellation', true)->firstOrFail();

        $cancelled = DB::transaction(function () use ($booking, $cancelStatus, $request): bool {
            $lockedBooking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if ($lockedBooking->status_id === $cancelStatus->id) {
                return false;
            }

            $lockedBooking->update(['status_id' => $cancelStatus->id]);
            $lockedBooking->statusHistory()->create([
                'status_id' => $cancelStatus->id,
                'notes' => $request->input('notes', 'Cancelled via API'),
            ]);

            return true;
        }, 3);

        if (! $cancelled) {
            if ($hasIdempotencyKey) {
                $this->idempotency->release($request, $endpoint);
            }

            return response()->json([
                'error' => 'already_cancelled',
                'detail' => 'This booking is already cancelled.',
            ], 422);
        }

        $booking = $booking->fresh(['client', 'service', 'provider', 'location', 'status']);

        if ($hasIdempotencyKey) {
            $this->idempotency->store($request, $endpoint, 200, ['data' => new BookingResource($booking)]);
        }

        return response()->json(['data' => new BookingResource($booking)]);
    }

    /** @return array{type: string, booking?: Booking, blocked_slot?: BlockedSlot}|null */
    private function findSchedulingConflict(
        int $locationId,
        int $clientId,
        ?int $providerId,
        Carbon $startTime,
        Carbon $endTime,
        ?int $excludeBookingId = null
    ): ?array {
        $locationConflict = Booking::where('location_id', $locationId)
            ->active()
            ->overlapping($startTime, $endTime, $excludeBookingId)
            ->first();

        if ($locationConflict) {
            return ['type' => 'location_booking', 'booking' => $locationConflict];
        }

        $clientConflict = Booking::where('client_id', $clientId)
            ->active()
            ->overlapping($startTime, $endTime, $excludeBookingId)
            ->first();

        if ($clientConflict) {
            return ['type' => 'client_booking', 'booking' => $clientConflict];
        }

        $blockedSlot = BlockedSlot::where('location_id', $locationId)
            ->when($providerId !== null, function ($query) use ($providerId) {
                $query->where(function ($query) use ($providerId) {
                    $query->whereNull('provider_id')->orWhere('provider_id', $providerId);
                });
            })
            ->where('start_time', '<', $endTime)
            ->where('end_time', '>', $startTime)
            ->first();

        return $blockedSlot ? ['type' => 'blocked_slot', 'blocked_slot' => $blockedSlot] : null;
    }

    /** @param array{type: string, booking?: Booking, blocked_slot?: BlockedSlot} $conflict */
    private function conflictResponse(array $conflict): JsonResponse
    {
        if ($conflict['type'] === 'blocked_slot') {
            $blockedSlot = $conflict['blocked_slot'];

            return response()->json([
                'error' => 'conflict',
                'detail' => 'Agenda block overlap',
                'conflicts_with' => [
                    'id' => $blockedSlot->id,
                    'start_time' => $blockedSlot->start_time->toIso8601String(),
                    'end_time' => $blockedSlot->end_time->toIso8601String(),
                    'type' => 'blocked_slot',
                ],
            ], 409);
        }

        $booking = $conflict['booking'];

        return response()->json([
            'error' => 'conflict',
            'detail' => $conflict['type'] === 'location_booking'
                ? 'Location overlap with existing booking'
                : 'Client overlap with existing booking',
            'conflicts_with' => [
                'id' => $booking->id,
                'start_time' => $booking->start_time->toIso8601String(),
                'end_time' => $booking->end_time->toIso8601String(),
            ],
        ], 409);
    }
}
