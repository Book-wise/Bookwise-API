<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Location;
use App\Models\Service;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class SlotAvailabilityService
{
    public function getAvailableSlots(
        int $locationId,
        string $startDate,
        ?int $serviceId = null,
        ?int $providerId = null,
        ?int $durationMinutes = null,
        ?int $slotInterval = null
    ): Collection {

        // ── 1. Resolver duración efectiva ──────────────────────────
        $service = $serviceId ? Service::find($serviceId) : null;

        $duration = $durationMinutes
            ?? $service?->duration_minutes
            ?? config('booking.default_duration_minutes', 30);

        // Prioridad: parámetro > servicio > config > fallback 30
        $interval = $slotInterval
            ?? $service?->slot_interval_minutes
            ?? config('booking.slot_interval_minutes', 30);

        // ── 2. Rango del día (timezone-aware) ──────────────────────
        $location = Location::findOrFail($locationId);
        $locationTz = new \DateTimeZone($location->timezone);
        $date = Carbon::parse($startDate);
        $dayStart = Carbon::parse($date->format('Y-m-d'), $locationTz)
            ->setTimeFromTimeString($location->opening_time ?? '09:00:00')
            ->setTimezone('UTC');
        $dayEnd = Carbon::parse($date->format('Y-m-d'), $locationTz)
            ->setTimeFromTimeString($location->closing_time ?? '19:00:00')
            ->setTimezone('UTC');

        // ── 3. Reservas activas del día ────────────────────────────
        $existingBookings = Booking::with('status')
            ->active()
            ->where('location_id', $locationId)
            ->when($providerId, fn ($q) => $q->where('provider_id', $providerId))
            ->whereDate('start_time', $date)
            ->get(['start_time', 'end_time', 'provider_id']);

        // ── 4. Generar slots y filtrar colisiones ──────────────────
        $slots = collect();
        $cursor = $dayStart->copy();

        while ($cursor->copy()->addMinutes($duration)->lte($dayEnd)) {
            $slotStart = $cursor->copy();
            $slotEnd = $cursor->copy()->addMinutes($duration);

            $hasCollision = $existingBookings->contains(function ($booking) use ($slotStart, $slotEnd) {
                return $slotStart->lt(Carbon::parse($booking->end_time))
                    && $slotEnd->gt(Carbon::parse($booking->start_time));
            });

            if (! $hasCollision) {
                $slots->push([
                    'start' => $slotStart->toIso8601String(),
                    'end' => $slotEnd->toIso8601String(),
                    'duration_minutes' => $duration,
                    'provider_id' => $providerId,
                ]);
            }

            $cursor->addMinutes($interval);
        }

        return $slots;
    }
}
