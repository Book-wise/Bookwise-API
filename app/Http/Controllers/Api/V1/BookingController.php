<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\BookingResource;
use App\Models\Booking;
use App\Models\BookingStatus;
use App\Models\Service;
use App\Models\Location;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class BookingController extends Controller
{
    // ── GET /v1/bookings ───────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $bookings = Booking::with(['client', 'service', 'provider', 'location', 'status', 'sale', 'packSession.clientPack'])
            // Provider filter: only show bookings for their locations
            ->when($user?->role?->value === 'provider', function ($query) use ($user) {
                $provider = $user->provider;
                if ($provider?->location_id) {
                    $query->where('location_id', $provider->location_id);
                }
            })
            ->when($request->client_id,   fn($q) => $q->where('client_id',   $request->client_id))
            ->when($request->service_id,  fn($q) => $q->where('service_id',  $request->service_id))
            ->when($request->provider_id, fn($q) => $q->where('provider_id', $request->provider_id))
            ->when($request->location_id, fn($q) => $q->where('location_id', $request->location_id))
            ->when($request->status_id,   fn($q) => $q->where('status_id',   $request->status_id))
            ->when($request->date_from,   fn($q) => $q->whereDate('start_time', '>=', $request->date_from))
            ->when($request->date_to,     fn($q) => $q->whereDate('start_time', '<=', $request->date_to))
            ->when($request->wc_order_id, fn($q) => $q->where('wc_order_id', $request->wc_order_id))
            ->orderBy('start_time', 'desc')
            ->paginate($request->per_page ?? 15);

        return response()->json(BookingResource::collection($bookings));
    }

    // ── GET /v1/bookings/{id} ──────────────────────────────────────
    public function show(int $id): JsonResponse
    {
        $booking = Booking::with(['client', 'service', 'provider', 'location', 'status', 'statusHistory.status', 'sale', 'packSession.clientPack'])
            ->findOrFail($id);

        return response()->json(['data' => new BookingResource($booking)]);
    }

    // ── POST /v1/bookings ──────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_time'       => ['required', 'date'],
            'end_time'         => ['nullable', 'date', 'after:start_time'],
            'service_id'       => ['required', 'integer', 'exists:services,id'],
            'provider_id'      => ['required', 'integer', 'exists:providers,id'],
            'client_id'        => ['required', 'integer', 'exists:clients,id'],
            'location_id'      => ['required', 'integer', 'exists:locations,id'],
            'status_id'        => ['required', 'integer', 'exists:booking_statuses,id'],
            'price'            => ['nullable', 'numeric', 'min:0'],
            'notes'            => ['nullable', 'string', 'max:1000'],
            'duration_minutes' => ['nullable', 'integer', 'min:15', 'max:480'],
            'wc_order_id'      => ['nullable', 'integer'],
        ]);

        $user     = $request->user();
        $service  = Service::findOrFail($validated['service_id']);
        $provider = \App\Models\Provider::findOrFail($validated['provider_id']);

        if ($user->isProvider() && (int) $user->provider_id !== (int) $validated['provider_id']) {
            return response()->json([
                'error'  => 'forbidden',
                'detail' => 'Solo podés crear reservas para tu propio perfil de profesional.',
            ], 403);
        }

        if ((int) $provider->location_id !== (int) $validated['location_id']) {
            return response()->json([
                'error'  => 'provider_location_mismatch',
                'detail' => 'El profesional no pertenece a la ubicación seleccionada.',
            ], 422);
        }

        $startTime = Carbon::parse($validated['start_time']);

        // Duración efectiva
        $effectiveDuration = $validated['duration_minutes']
            ?? $service->duration_minutes
            ?? config('booking.default_duration_minutes', 30);

        $endTime = isset($validated['end_time'])
            ? Carbon::parse($validated['end_time'])
            : $startTime->copy()->addMinutes($effectiveDuration);

        // Check for booking overlaps (provider, location, or client)
        $conflict = $this->checkBookingOverlap(
            $validated['provider_id'],
            $validated['location_id'],
            $validated['client_id'],
            $startTime,
            $endTime
        );

        if ($conflict) {
            $conflictType = match (true) {
                $conflict->provider_id === (int) $validated['provider_id'] => 'Provider overlap with existing booking',
                $conflict->location_id === (int) $validated['location_id'] => 'Location overlap with existing booking',
                default                                                     => 'Client overlap with existing booking',
            };

            return response()->json([
                'error'       => 'conflict',
                'detail'     => $conflictType,
                'conflicts_with' => [
                    'id'        => $conflict->id,
                    'start_time' => $conflict->start_time->toIso8601String(),
                    'end_time'   => $conflict->end_time->toIso8601String(),
                ],
            ], 409);
        }

        $booking = Booking::create([
            ...$validated,
            'end_time'                => $endTime,
            'custom_duration_minutes' => $validated['duration_minutes'] ?? null,
            'price'                   => $validated['price'] ?? $service->price,
        ]);

        $booking->load(['client', 'service', 'provider', 'location', 'status']);

        return response()->json(['data' => new BookingResource($booking)], 201);
    }

    // ── PATCH /v1/bookings/{id} ────────────────────────────────────
    public function update(Request $request, int $id): JsonResponse
    {
        $booking = Booking::findOrFail($id);

        $validated = $request->validate([
            'start_time'  => ['sometimes', 'date'],
            'end_time'    => ['sometimes', 'date', 'after:start_time'],
            'status_id'   => ['sometimes', 'integer', 'exists:booking_statuses,id'],
            'price'       => ['sometimes', 'numeric', 'min:0'],
            'notes'       => ['sometimes', 'nullable', 'string', 'max:1000'],
            'provider_id' => ['sometimes', 'nullable', 'integer', 'exists:providers,id'],
        ]);

        // Check for booking overlaps if time, provider, location, or client is being changed
        if (isset($validated['start_time']) || isset($validated['end_time'])
            || isset($validated['provider_id']) || isset($validated['location_id'])
            || isset($validated['client_id'])) {

            $startTime = isset($validated['start_time'])
                ? Carbon::parse($validated['start_time'])
                : $booking->start_time;

            $endTime = isset($validated['end_time'])
                ? Carbon::parse($validated['end_time'])
                : ($booking->end_time ?? $startTime->copy()->addMinutes($booking->effective_duration_minutes));

            $providerId = $validated['provider_id'] ?? $booking->provider_id;
            $locationId = $validated['location_id'] ?? $booking->location_id;
            $clientId   = $validated['client_id']   ?? $booking->client_id;

            $conflict = $this->checkBookingOverlap(
                $providerId,
                $locationId,
                $clientId,
                $startTime,
                $endTime,
                $booking->id
            );

            if ($conflict) {
                $conflictType = match (true) {
                    $conflict->provider_id === (int) $providerId => 'Provider overlap with existing booking',
                    $conflict->location_id === (int) $locationId => 'Location overlap with existing booking',
                    default                                       => 'Client overlap with existing booking',
                };

                return response()->json([
                    'error'       => 'conflict',
                    'detail'     => $conflictType,
                    'conflicts_with' => [
                        'id'        => $conflict->id,
                        'start_time' => $conflict->start_time->toIso8601String(),
                        'end_time'   => $conflict->end_time->toIso8601String(),
                    ],
                ], 409);
            }
        }

        $booking->update($validated);
        $booking->load(['client', 'service', 'provider', 'location', 'status']);

        return response()->json(['data' => new BookingResource($booking)]);
    }

    // ── PATCH /v1/bookings/{id}/cancel ────────────────────────────
    public function cancel(Request $request, int $id): JsonResponse
    {
        $booking = Booking::findOrFail($id);

        $cancelStatus = BookingStatus::where('is_cancellation', true)->firstOrFail();

        // Ya está cancelada
        if ($booking->status_id === $cancelStatus->id) {
            return response()->json([
                'error'  => 'already_cancelled',
                'detail' => 'This booking is already cancelled.',
            ], 422);
        }

        $booking->update(['status_id' => $cancelStatus->id]);

        // Registrar en historial
        $booking->statusHistory()->create([
            'status_id' => $cancelStatus->id,
            'notes'     => $request->input('notes', 'Cancelled via API'),
        ]);

        $booking->load(['client', 'service', 'provider', 'location', 'status']);

        return response()->json(['data' => new BookingResource($booking)]);
    }

    // ── Private: Check for booking overlaps ───────────────────────────
    private function checkBookingOverlap(
        int $providerId,
        int $locationId,
        int $clientId,
        Carbon $startTime,
        Carbon $endTime,
        ?int $excludeBookingId = null
    ): ?Booking {
        $base = fn($column, $value) => Booking::where($column, $value)
            ->active()
            ->overlapping($startTime, $endTime, $excludeBookingId)
            ->first();

        return $base('provider_id', $providerId)
            ?? $base('location_id', $locationId)
            ?? $base('client_id', $clientId);
    }
}
