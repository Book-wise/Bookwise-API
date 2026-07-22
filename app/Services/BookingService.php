<?php

namespace App\Services;

use App\Enums\BookingSource;
use App\Models\BlockedSlot;
use App\Models\Booking;
use Carbon\Carbon;

class BookingService
{
    /**
     * Checks both active bookings and agenda blocks. Callers that create or
     * reschedule must call this only after acquiring SchedulingLockService.
     */
    public function verifyAvailability(int $locationId, string $startTime, string $endTime, ?int $providerId = null): bool
    {
        $start = Carbon::parse($startTime);
        $end = Carbon::parse($endTime);

        $bookingExists = Booking::where('location_id', $locationId)
            ->active()
            ->overlapping($start, $end)
            ->exists();

        if ($bookingExists) {
            return false;
        }

        return ! BlockedSlot::where('location_id', $locationId)
            ->when($providerId !== null, function ($query) use ($providerId) {
                $query->where(function ($query) use ($providerId) {
                    $query->whereNull('provider_id')->orWhere('provider_id', $providerId);
                });
            })
            ->where('start_time', '<', $end)
            ->where('end_time', '>', $start)
            ->exists();
    }

    /**
     * The WooCommerce caller must hold its location scheduling lock before
     * invoking this method; wc_order_id provides the durable replay key.
     *
     * @param  array<string, mixed>  $data
     */
    public function findOrCreateBooking(array $data, ?BookingSource $createdVia = BookingSource::OnlineWebhook): Booking
    {
        $existing = Booking::where('wc_order_id', $data['wc_order_id'])->lockForUpdate()->first();

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
            'created_via' => $createdVia,
            'last_modified_via' => $createdVia,
        ]);
    }
}
