<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BookingSource;
use App\Enums\UserRole;
use App\Events\BookingCancelled;
use App\Events\BookingConfirmed;
use App\Events\BookingCreated;
use App\Http\Controllers\Controller;
use App\Http\Requests\BookingStoreRequest;
use App\Http\Requests\BookingUpdateRequest;
use App\Http\Resources\V1\BookingResource;
use App\Models\Booking;
use App\Models\BookingStatus;
use App\Models\Location;
use App\Models\Service;
use App\Models\ServicePack;
use App\Models\User;
use App\Services\IdempotencyService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function __construct(
        private readonly IdempotencyService $idempotency,
    ) {}

    // ── Role → BookingSource ───────────────────────────────────────

    /**
     * Map the authenticated user's role to a BookingSource value.
     * Admin / provider → admin_calendar, agent → agent, fallback → admin_calendar.
     */
    private function resolveBookingSource(User $user): BookingSource
    {
        return match ($user->role) {
            UserRole::AGENT => BookingSource::Agent,
            default => BookingSource::AdminCalendar,
        };
    }

    // ── GET /v1/bookings ───────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $bookings = Booking::with(['client', 'service', 'provider', 'location', 'status', 'sale', 'packSession.clientPack'])
            // Provider filter: only show bookings for their locations
            ->when($user?->role?->value === 'provider', function ($query) use ($request) {
                $locationIds = $request->attributes->get('provider_location_ids', []);
                if (! empty($locationIds)) {
                    $query->whereIn('location_id', $locationIds);
                }
            })
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

        return response()->json(BookingResource::collection($bookings));
    }

    // ── GET /v1/bookings/{id} ──────────────────────────────────────
    public function show(int $id): JsonResponse
    {
        $booking = Booking::with(['client', 'service', 'provider', 'location', 'status', 'statusHistory.status', 'sale', 'packSession.clientPack'])
            ->findOrFail($id);

        $this->authorize('view', $booking);

        return response()->json(['data' => new BookingResource($booking)]);
    }

    // ── POST /v1/bookings ──────────────────────────────────────────
    public function store(BookingStoreRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // Resolve service: direct or from a service_pack
        $serviceId = $validated['service_id'] ?? null;
        $servicePackId = $validated['service_pack_id'] ?? null;

        if ($serviceId && $servicePackId) {
            return response()->json([
                'error' => 'validation',
                'detail' => 'Provide either service_id or service_pack_id, not both.',
            ], 422);
        }

        if (! $serviceId && ! $servicePackId) {
            return response()->json([
                'error' => 'validation',
                'detail' => 'Service is required. Provide either service_id or service_pack_id.',
            ], 422);
        }

        $service = $serviceId
            ? Service::findOrFail($serviceId)
            : ServicePack::findOrFail($servicePackId)->service;

        $validated['service_id'] = $service->id;
        unset($validated['service_pack_id']);

        $endpoint = 'POST /v1/bookings';
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

            if ($status === 2) {
                return $this->idempotency->check($request, $endpoint) ?? response()->json([
                    'error' => 'conflict',
                    'detail' => 'A request with this idempotency key is already in progress or conflicts.',
                ], 409);
            }
        }

        // Parse start_time in the location's timezone,
        // then convert to app timezone for consistent storage.
        $location = Location::findOrFail($validated['location_id']);
        $startTime = $this->parseInLocationTimezone($validated['start_time'], $location);
        $validated['start_time'] = $startTime;

        // Effective duration: use explicit duration, service default, or fallback
        $effectiveDuration = $validated['duration_minutes']
            ?? $service->duration_minutes
            ?? (int) config('booking.default_duration_minutes', 30);

        $endTime = isset($validated['end_time'])
            ? $this->parseInLocationTimezone($validated['end_time'], $location)
            : $startTime->copy()->addMinutes($effectiveDuration);

        $validated['end_time'] = $endTime;

        // Check for booking overlaps (location or client)
        $conflict = $this->checkBookingOverlap(
            $validated['location_id'],
            $validated['client_id'],
            $startTime,
            $endTime
        );

        if ($conflict) {
            if ($hasIdempotencyKey) {
                $this->idempotency->release($request, $endpoint);
            }

            $conflictType = $conflict->location_id === (int) $validated['location_id']
                ? 'Location overlap with existing booking'
                : 'Client overlap with existing booking';

            return response()->json([
                'error' => 'conflict',
                'detail' => $conflictType,
                'conflicts_with' => [
                    'id' => $conflict->id,
                    'start_time' => $conflict->start_time->toIso8601String(),
                    'end_time' => $conflict->end_time->toIso8601String(),
                ],
            ], 409);
        }

        $source = $this->resolveBookingSource($request->user());

        try {
            $booking = Booking::create([
                ...$validated,
                'end_time' => $endTime,
                'custom_duration_minutes' => $validated['duration_minutes'] ?? null,
                'price' => $validated['price'] ?? $service->price,
                'created_via' => $source,
                'last_modified_via' => $source,
            ]);
        } catch (\Throwable $e) {
            if ($hasIdempotencyKey) {
                $this->idempotency->release($request, $endpoint);
            }

            throw $e;
        }

        $booking->load(['client', 'service', 'provider', 'location', 'status']);

        if ($hasIdempotencyKey) {
            $this->idempotency->store($request, $endpoint, 201, ['data' => new BookingResource($booking)]);
        }

        $isCancellation = $booking->status->is_cancellation === true;

        event(new BookingCreated($booking));

        if (! $isCancellation) {
            event(new BookingConfirmed($booking));
        }

        return response()->json(['data' => new BookingResource($booking)], 201);
    }

    // ── PATCH /v1/bookings/{id} ────────────────────────────────────
    public function update(BookingUpdateRequest $request, int $id): JsonResponse
    {
        $booking = Booking::findOrFail($id);

        $this->authorize('update', $booking);

        $validated = $request->validated();

        // Check for booking overlaps if time, location, or client is being changed
        if (isset($validated['start_time']) || isset($validated['end_time'])
            || isset($validated['location_id']) || isset($validated['client_id'])) {

            $locationId = isset($validated['location_id'])
                ? $validated['location_id']
                : $booking->location_id;

            $location = Location::findOrFail($locationId);

            $startTime = isset($validated['start_time'])
                ? $this->parseInLocationTimezone($validated['start_time'], $location)
                : $booking->start_time;

            $endTime = isset($validated['end_time'])
                ? $this->parseInLocationTimezone($validated['end_time'], $location)
                : ($booking->end_time ?? $startTime->copy()->addMinutes($booking->effective_duration_minutes));

            $clientId = isset($validated['client_id'])
                ? $validated['client_id']
                : $booking->client_id;

            $validated['start_time'] = $startTime;
            $validated['end_time'] = $endTime;

            $conflict = $this->checkBookingOverlap(
                $locationId,
                $clientId,
                $startTime,
                $endTime,
                $booking->id
            );

            if ($conflict) {
                $conflictType = $conflict->location_id === $locationId
                    ? 'Location overlap with existing booking'
                    : 'Client overlap with existing booking';

                return response()->json([
                    'error' => 'conflict',
                    'detail' => $conflictType,
                    'conflicts_with' => [
                        'id' => $conflict->id,
                        'start_time' => $conflict->start_time->toIso8601String(),
                        'end_time' => $conflict->end_time->toIso8601String(),
                    ],
                ], 409);
            }
        }

        $booking->update([
            ...$validated,
            'last_modified_via' => $this->resolveBookingSource($request->user()),
        ]);
        $booking->load(['client', 'service', 'provider', 'location', 'status']);

        return response()->json(['data' => new BookingResource($booking)]);
    }

    // ── PATCH /v1/bookings/{id}/cancel ────────────────────────────
    public function cancel(Request $request, int $id): JsonResponse
    {
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

            if ($status === 2) {
                return $this->idempotency->check($request, $endpoint) ?? response()->json([
                    'error' => 'conflict',
                    'detail' => 'A request with this idempotency key is already in progress or conflicts.',
                ], 409);
            }
        }

        $booking = Booking::findOrFail($id);

        $this->authorize('cancel', $booking);

        $cancelStatus = BookingStatus::where('is_cancellation', true)->firstOrFail();

        // Ya está cancelada
        if ($booking->status_id === $cancelStatus->id) {
            if ($hasIdempotencyKey) {
                $this->idempotency->release($request, $endpoint);
            }

            return response()->json([
                'error' => 'already_cancelled',
                'detail' => 'This booking is already cancelled.',
            ], 422);
        }

        try {
            $booking->update([
                'status_id' => $cancelStatus->id,
                'last_modified_via' => $this->resolveBookingSource($request->user()),
            ]);

            // Registrar en historial
            $booking->statusHistory()->create([
                'status_id' => $cancelStatus->id,
                'notes' => $request->input('notes', 'Cancelled via API'),
            ]);
        } catch (\Throwable $e) {
            if ($hasIdempotencyKey) {
                $this->idempotency->release($request, $endpoint);
            }

            throw $e;
        }

        $booking->load(['client', 'service', 'provider', 'location', 'status']);

        if ($hasIdempotencyKey) {
            $this->idempotency->store($request, $endpoint, 200, ['data' => new BookingResource($booking)]);
        }

        event(new BookingCancelled($booking));

        return response()->json(['data' => new BookingResource($booking)]);
    }

    // ── Private: Check for booking overlaps ───────────────────────────
    /**
     * Check if there are any overlapping bookings (location or client).
     *
     * @param  int|null  $excludeBookingId  Booking ID to exclude from the search
     * @return Booking|null The conflicting booking, or null if no conflict
     */
    private function checkBookingOverlap(
        int $locationId,
        int $clientId,
        Carbon $startTime,
        Carbon $endTime,
        ?int $excludeBookingId = null
    ): ?Booking {
        // Check location overlap
        $locationConflict = Booking::where('location_id', $locationId)
            ->active()
            ->overlapping($startTime, $endTime, $excludeBookingId)
            ->first();

        if ($locationConflict) {
            return $locationConflict;
        }

        // Check client overlap
        $clientConflict = Booking::where('client_id', $clientId)
            ->active()
            ->overlapping($startTime, $endTime, $excludeBookingId)
            ->first();

        return $clientConflict;
    }

    // ── Private: Parse datetime in location timezone ───────────────────

    /**
     * Parse a datetime string using the location's timezone and convert
     * to the application's default timezone for storage consistency.
     *
     * If the datetime has an explicit UTC offset (e.g. "Z", "+00:00"),
     * Carbon respects it and the location timezone is used only for the
     * final conversion. If no offset is present, the location timezone
     * is used to interpret the datetime as local time.
     */
    private function parseInLocationTimezone(string $datetime, Location $location): Carbon
    {
        return Carbon::parse($datetime, new \DateTimeZone($location->timezone))
            ->setTimezone(config('app.timezone'));
    }
}
