<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BookingSource;
use App\Enums\UserRole;
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
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
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

        // Resolver servicio: directo o desde un service_pack
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

        // Inyectar service_id en los datos de creación y limpiar service_pack_id
        $validated['service_id'] = $service->id;
        unset($validated['service_pack_id']);

        // Interpretar start_time con el timezone de la location
        // Si start_time trae offset explícito (UTC), Carbon lo respeta.
        // Si no trae offset, se interpreta como hora local de la sucursal.
        // Se convierte a America/Santiago (app.timezone) para almacenamiento
        // consistente con el resto de la base de datos.
        $location = Location::findOrFail($validated['location_id']);
        $locationTz = new \DateTimeZone($location->timezone);
        $startTime = Carbon::parse($validated['start_time'], $locationTz)
            ->setTimezone(config('app.timezone'));
        $validated['start_time'] = $startTime;

        // Effective duration: use explicit duration, service default, or fallback
        $effectiveDuration = $validated['duration_minutes']
            ?? $service->duration_minutes
            ?? (int) config('booking.default_duration_minutes', 30);

        $endTime = isset($validated['end_time'])
            ? Carbon::parse($validated['end_time'], $locationTz)->setTimezone(config('app.timezone'))
            : $startTime->copy()->addMinutes($effectiveDuration);

        // Check for booking overlaps (location or client)
        $conflict = $this->checkBookingOverlap(
            $validated['location_id'],
            $validated['client_id'],
            $startTime,
            $endTime
        );

        if ($conflict) {
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

        $booking = Booking::create([
            ...$validated,
            'end_time' => $endTime,
            'custom_duration_minutes' => $validated['duration_minutes'] ?? null,
            'price' => $validated['price'] ?? $service->price,
            'created_via' => $source,
            'last_modified_via' => $source,
        ]);

        $booking->load(['client', 'service', 'provider', 'location', 'status']);

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

            // Resolve the effective location for timezone-aware parsing
            $locationId = isset($validated['location_id'])
                ? $validated['location_id']
                : $booking->location_id;

            $locationTz = new \DateTimeZone(Location::findOrFail($locationId)->timezone);

            $startTime = isset($validated['start_time'])
                ? Carbon::parse($validated['start_time'], $locationTz)->setTimezone(config('app.timezone'))
                : $booking->start_time;

            $endTime = isset($validated['end_time'])
                ? Carbon::parse($validated['end_time'], $locationTz)->setTimezone(config('app.timezone'))
                : ($booking->end_time ?? $startTime->copy()->addMinutes($booking->effective_duration_minutes));

            $clientId = isset($validated['client_id'])
                ? $validated['client_id']
                : $booking->client_id;

            // Persist the timezone-converted values so they are stored correctly
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
        $booking = Booking::findOrFail($id);

        $this->authorize('cancel', $booking);

        $cancelStatus = BookingStatus::where('is_cancellation', true)->firstOrFail();

        // Ya está cancelada
        if ($booking->status_id === $cancelStatus->id) {
            return response()->json([
                'error' => 'already_cancelled',
                'detail' => 'This booking is already cancelled.',
            ], 422);
        }

        $booking->update([
            'status_id' => $cancelStatus->id,
            'last_modified_via' => $this->resolveBookingSource($request->user()),
        ]);

        // Registrar en historial
        $booking->statusHistory()->create([
            'status_id' => $cancelStatus->id,
            'notes' => $request->input('notes', 'Cancelled via API'),
        ]);

        $booking->load(['client', 'service', 'provider', 'location', 'status']);

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
}
