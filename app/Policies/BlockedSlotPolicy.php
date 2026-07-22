<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\BlockedSlot;
use App\Models\User;

class BlockedSlotPolicy
{
    /**
     * Determine whether the user can view the blocked slot.
     */
    public function view(User $user, BlockedSlot $slot): bool
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
                && $provider->location_id === $slot->location_id;
        }

        return false;
    }

    /**
     * Determine whether the user can update the blocked slot.
     */
    public function update(User $user, BlockedSlot $slot): bool
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
                && $provider->location_id === $slot->location_id;
        }

        return false;
    }

    /**
     * Determine whether the user can delete the blocked slot.
     */
    public function delete(User $user, BlockedSlot $slot): bool
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
                && $provider->location_id === $slot->location_id;
        }

        return false;
    }
}
