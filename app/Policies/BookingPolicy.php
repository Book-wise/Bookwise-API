<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\Location;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class BookingPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return ! $user->isProvider() || $this->providerLocationId($user) !== null;
    }

    public function view(User $user, Booking $booking): bool
    {
        return ! $user->isProvider() || $booking->location_id === $this->providerLocationId($user);
    }

    public function create(User $user, Location $location): bool
    {
        return ! $user->isProvider() || $location->id === $this->providerLocationId($user);
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

        $locationId = $this->providerLocationId($user);

        return $locationId === null
            ? $query->whereRaw('1 = 0')
            : $query->where('location_id', $locationId);
    }

    private function providerLocationId(User $user): ?int
    {
        return $user->provider?->location_id;
    }
}
