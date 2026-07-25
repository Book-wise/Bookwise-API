<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Region;

class LocationService
{
    /**
     * Resolve the timezone string for a given region.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function resolveTimezone(int $regionId): string
    {
        return Region::findOrFail($regionId)->timezone;
    }

    /**
     * Check if a location has future non-finalized bookings that would
     * prevent safe deactivation.
     *
     * @return array{has_conflicts: bool, bookings: array}
     */
    public function checkDeactivationPreflight(int $locationId): array
    {
        $conflictingBookings = Booking::where('location_id', $locationId)
            ->where('start_time', '>', now())
            ->whereHas('status', fn ($q) => $q->where('is_cancellation', false))
            ->with(['provider', 'location', 'status'])
            ->get();

        if ($conflictingBookings->isEmpty()) {
            return [
                'has_conflicts' => false,
                'bookings' => [],
            ];
        }

        return [
            'has_conflicts' => true,
            'bookings' => $conflictingBookings->map(fn (Booking $b) => [
                'id' => $b->id,
                'date' => $b->start_time->toDateString(),
                'time' => $b->start_time->format('H:i'),
                'provider_name' => $b->provider?->full_name ?? $b->provider?->first_name ?? '—',
                'location_name' => $b->location?->name ?? '—',
                'status' => $b->status?->name ?? '—',
            ])->toArray(),
        ];
    }
}
