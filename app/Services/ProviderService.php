<?php

namespace App\Services;

use App\Models\Booking;

class ProviderService
{
    /**
     * Check if a provider has future live bookings that would prevent
     * safe deactivation.
     *
     * @return array{has_conflicts: bool, bookings: array}
     */
    public function checkDeactivationPreflight(int $providerId): array
    {
        $conflictingBookings = Booking::where('provider_id', $providerId)
            ->where('start_time', '>', now())
            ->whereHas('status', fn ($q) => $q->where('is_cancellation', false)->where('is_finalized', false))
            ->with(['client', 'status'])
            ->orderBy('start_time')
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
                'client_name' => $b->client ? (trim($b->client->first_name.' '.$b->client->last_name) ?: '—') : '—',
                'status' => $b->status?->name ?? '—',
            ])->toArray(),
        ];
    }
}
