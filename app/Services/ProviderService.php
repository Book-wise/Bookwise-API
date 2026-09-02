<?php

namespace App\Services;

use App\Models\Booking;
use Carbon\Carbon;
use Illuminate\Support\Collection;

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
            'bookings' => $this->mapBookings($conflictingBookings),
        ];
    }

    /**
     * Upcoming bookings of a provider for the calendar deactivation pre-check
     * (GET /providers/{id}/bookings). Supports `from` and explicit `statusIds`;
     * without statusIds it uses the live/final flag predicate (same as the 409).
     *
     * @return array<int, array{id: int, date: string, time: string, client_name: string, status: string}>
     */
    public function upcomingBookings(int $providerId, ?string $from = null, ?array $statusIds = null): array
    {
        $query = Booking::query()
            ->where('provider_id', $providerId)
            ->where('start_time', '>=', $from !== null ? Carbon::parse($from) : now())
            ->with(['client', 'status'])
            ->orderBy('start_time');

        if (! empty($statusIds)) {
            $query->whereIn('status_id', $statusIds);
        } else {
            $query->whereHas('status', fn ($q) => $q->where('is_cancellation', false)->where('is_finalized', false));
        }

        return $this->mapBookings($query->get());
    }

    /**
     * @param  Collection<int, Booking>  $bookings
     * @return array<int, array{id: int, date: string, time: string, client_name: string, status: string}>
     */
    private function mapBookings($bookings): array
    {
        return $bookings
            ->map(fn (Booking $b) => [
                'id' => $b->id,
                'date' => $b->start_time->toDateString(),
                'time' => $b->start_time->format('H:i'),
                'client_name' => $b->client ? (trim($b->client->first_name.' '.$b->client->last_name) ?: '—') : '—',
                'status' => $b->status?->name ?? '—',
            ])
            ->values()
            ->toArray();
    }
}
