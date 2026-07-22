<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\User;

class BookingPolicy
{
    /**
     * Determine whether the user can view the booking.
     */
    public function view(User $user, Booking $booking): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->role === UserRole::AGENT) {
            return true;
        }

        if ($user->isProvider()) {
            $provider = $user->provider;

            return $provider !== null
                && $provider->location_id === $booking->location_id;
        }

        return false;
    }

    /**
     * Determine whether the user can update the booking.
     */
    public function update(User $user, Booking $booking): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->role === UserRole::AGENT) {
            return true;
        }

        if ($user->isProvider()) {
            $provider = $user->provider;

            return $provider !== null
                && $provider->location_id === $booking->location_id;
        }

        return false;
    }

    /**
     * Determine whether the user can cancel the booking.
     */
    public function cancel(User $user, Booking $booking): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->role === UserRole::AGENT) {
            return true;
        }

        if ($user->isProvider()) {
            $provider = $user->provider;

            return $provider !== null
                && $provider->location_id === $booking->location_id;
        }

        return false;
    }
}
