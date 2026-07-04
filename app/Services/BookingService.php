<?php

namespace App\Services;

use App\Models\Booking;
use Carbon\Carbon;

class BookingService
{
    /**
     * Verify if a time slot is available at a given location.
     * Returns true if slot is free (no active overlapping bookings), false if occupied.
     */
    public function verifyAvailability(int $locationId, string $startTime, string $endTime): bool
    {
        return ! Booking::where('location_id', $locationId)
            ->active()
            ->overlapping(Carbon::parse($startTime), Carbon::parse($endTime))
            ->exists();
    }

    /**
     * Find an existing booking by wc_order_id or create a new one.
     * Provides idempotency: if a booking with the given wc_order_id exists, returns it.
     */
    public function findOrCreateBooking(array $data): Booking
    {
        $existing = Booking::where('wc_order_id', $data['wc_order_id'])->first();

        if ($existing) {
            return $existing;
        }

        return Booking::create([
            'wc_order_id' => $data['wc_order_id'],
            'client_id' => $data['client_id'],
            'service_id' => $data['service_id'],
            'location_id' => $data['location_id'],
            'status_id' => $data['status_id'],
            'start_time' => $data['start_time'],
            'end_time' => $data['end_time'],
            'custom_duration_minutes' => $data['custom_duration_minutes'] ?? null,
            'price' => $data['price'] ?? 0,
            'notes' => $data['notes'] ?? null,
            'provider_id' => null,
        ]);
    }
}
