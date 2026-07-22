<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\Location;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class BookingPolicy
{
    public function viewAny(User $user): bool
    {
        return ! $user->isProvider() || $user->provider_id !== null;
    }

    public function view(User $user, Booking $booking): bool
    {
        if ($user->isAdmin() || $user->role === UserRole::AGENT) {
            return true;
        }

        return $user->isProvider() && (int) $booking->provider_id === (int) $user->provider_id;
    }

    public function create(User $user, Location $location): bool
    {
        if ($user->isAdmin() || $user->role === UserRole::AGENT) {
            return true;
        }

        return $user->isProvider()
            && $user->provider !== null
            && (int) $user->provider->location_id === (int) $location->id;
    }

    public function update(User $user, Booking $booking): bool
    {
        return $this->view($user, $booking);
    }

    public function cancel(User $user, Booking $booking): bool
    {
        return $this->view($user, $booking);
    }

    /**
     * @param  Builder<Booking>  $query
     * @return Builder<Booking>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (! $user->isProvider()) {
            return $query;
        }

        return $user->provider_id === null
            ? $query->whereRaw('1 = 0')
            : $query->where('provider_id', $user->provider_id);
    }
}
