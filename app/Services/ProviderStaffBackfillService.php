<?php

namespace App\Services;

use App\Enums\BusinessRole;
use App\Models\Provider;
use App\Models\Role;
use Illuminate\Support\Facades\DB;

/**
 * One-off (idempotent) data backfill that gives the default `staff` business
 * role to providers that currently hold NO business role, so they become
 * selectable for bookings. Invoked by the
 * `backfill_provider_staff_roles` migration.
 *
 * Conservative by design:
 *  - Only providers whose linked user has a `tenant_id` are eligible; the role
 *    is scoped to that tenant (the user_role pivot is the tenant source of truth).
 *  - A provider whose linked user already holds ANY role in that tenant is left
 *    untouched — never add/remove/duplicate a role set.
 *  - Providers WITHOUT a linked user are SKIPPED (no user is manufactured here):
 *    those professionals receive `staff` only when someone assigns roles via
 *    PATCH /providers/{id}/roles or the store() creation default.
 */
class ProviderStaffBackfillService
{
    /**
     * @return int number of providers that received the `staff` role
     */
    public function backfill(): int
    {
        $staff = Role::query()->where('slug', BusinessRole::STAFF->value)->first();

        // RoleSeeder has not run — nothing to attach.
        if ($staff === null) {
            return 0;
        }

        return DB::transaction(function () use ($staff): int {
            $assigned = 0;

            Provider::query()
                ->with('user')
                ->whereHas('user')
                ->get()
                ->each(function (Provider $provider) use ($staff, &$assigned): void {
                    $user = $provider->user;

                    // No linked user or no tenant to scope the pivot to → skip.
                    if ($user === null || $user->tenant_id === null) {
                        return;
                    }

                    // Only touch providers that currently hold NO role under the
                    // tenant — providers with an existing role set are untouched.
                    $hasRole = $user->roles()
                        ->wherePivot('tenant_id', $user->tenant_id)
                        ->exists();

                    if ($hasRole) {
                        return;
                    }

                    $user->roles()->attach($staff->id, ['tenant_id' => $user->tenant_id]);

                    $assigned++;
                });

            return $assigned;
        });
    }
}
