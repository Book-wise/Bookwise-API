<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BusinessRole;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProviderStoreRequest;
use App\Http\Requests\ProviderUpdateRequest;
use App\Http\Requests\V1\AssignProviderRolesRequest;
use App\Http\Requests\V1\ProviderIndexRequest;
use App\Http\Resources\V1\ProviderResource;
use App\Models\Provider;
use App\Models\Role;
use App\Models\User;
use App\Services\ProviderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProviderController extends Controller
{
    /**
     * GET /api/v1/providers — global list (BR22) with an optional `roles[]`
     * attendance filter scoped to the caller's tenant (REQ-1..REQ-3).
     *
     * When a non-empty roles set is applied, only providers whose linked
     * user holds at least one requested slug on a user_role pivot whose
     * tenant_id equals the caller's tenant are returned (provider-level
     * whereHas → no duplicated rows, composes with active/location/service).
     * A tenantless caller sending a non-empty roles set gets 409
     * onboarding_required (BR18 parity); empty/absent roles leave the
     * unfiltered global list unchanged.
     */
    public function index(ProviderIndexRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $tenantId = $user->tenant_id;

        // An explicitly empty roles array (e.g. `{"roles":[]}`) validates to []
        // and MUST behave as if the filter were absent (REQ-1/S-5), so the
        // trigger is a NON-EMPTY set — Laravel's filled() treats [] as filled.
        $roles = $request->validated('roles') ?? [];
        $hasRoleFilter = $roles !== [];

        if ($hasRoleFilter && $tenantId === null) {
            return response()->json([
                'error' => 'onboarding_required',
                'detail' => 'Debes completar la creación de tu negocio antes de filtrar profesionales por rol.',
            ], 409);
        }

        $providers = Provider::query()
            ->with([
                'location',
                'services',
                // N+1-free nested roles, scoped to the caller tenant so
                // foreign-tenant pivot rows never leak (REQ-5/S-10).
                'user.roles' => fn ($q) => $q
                    ->wherePivot('tenant_id', $tenantId)
                    ->orderBy('roles.id'),
            ])
            ->when($hasRoleFilter, fn ($q) => $q->whereHas(
                'user.roles',
                fn ($q) => $q->whereIn('roles.slug', $roles)
                    ->where('user_role.tenant_id', $tenantId)
            ))
            ->when($request->location_id, fn ($q) => $q->where('location_id', $request->location_id)
            )
            ->when($request->service_id, function ($q) use ($request) {
                $q->whereHas('services', function ($q) use ($request) {
                    $q->where('services.id', $request->service_id);
                });
            })
            ->when($request->active !== null, function ($q) use ($request) {
                $q->where('active', filter_var($request->active, FILTER_VALIDATE_BOOLEAN));
            })
            ->orderBy('first_name')
            ->paginate($request->per_page ?? 15);

        return response()->json(ProviderResource::collection($providers));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $provider = Provider::with([
            'location',
            'services',
            'user.roles' => fn ($q) => $q
                ->wherePivot('tenant_id', $user->tenant_id)
                ->orderBy('roles.id'),
        ])->findOrFail($id);

        return response()->json(['data' => new ProviderResource($provider)]);
    }

    /**
     * GET /api/v1/providers/{id}/bookings — upcoming bookings pre-check
     * (support for the calendar deactivation dialog; the PATCH 409 remains the
     * race-free source of truth).
     *
     * Query params: from (Y-m-d H:i, optional, default now), status_ids[]
     * (optional; when absent the live/final flag predicate is used).
     */
    public function bookings(Request $request, int $id, ProviderService $providerService): JsonResponse
    {
        $provider = Provider::find($id);

        if (! $provider) {
            return response()->json([
                'error' => 'provider_not_found',
                'detail' => 'El profesional no existe.',
            ], 404);
        }

        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'status_ids' => ['nullable', 'array'],
            'status_ids.*' => ['integer', 'exists:booking_statuses,id'],
        ]);

        return response()->json([
            'bookings' => $providerService->upcomingBookings(
                $provider->id,
                $validated['from'] ?? null,
                $validated['status_ids'] ?? null,
            ),
        ]);
    }

    public function store(ProviderStoreRequest $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $provider = Provider::create($request->validated());

        // Default business role for a new professional is `staff` so they can be
        // selected for bookings. This is a sensible default only — the admin can
        // change it later via PATCH /providers/{id}/roles (never a locked rule).
        $this->assignDefaultStaffRole($provider, $admin);

        $provider->load(['location', 'services']);

        return response()->json([
            'data' => new ProviderResource($provider),
            'message' => 'Profesional creado exitosamente',
        ], 201);
    }

    public function update(ProviderUpdateRequest $request, int $id, ProviderService $providerService): JsonResponse
    {
        $provider = Provider::findOrFail($id);

        // Detect if the request is trying to deactivate the provider. Providers
        // never support a forced bypass: a stray `force` key is ignored because
        // ProviderUpdateRequest has no such rule and validated() drops it.
        $isTryingToDeactivate = $request->has('active')
            && $provider->active
            && ! filter_var($request->input('active'), FILTER_VALIDATE_BOOLEAN);

        $isActivating = $request->has('active')
            && ! $provider->active
            && filter_var($request->input('active'), FILTER_VALIDATE_BOOLEAN);

        // Run preflight check when deactivating
        if ($isTryingToDeactivate) {
            $preflight = $providerService->checkDeactivationPreflight($provider->id);

            if ($preflight['has_conflicts']) {
                $bookingCount = count($preflight['bookings']);

                return response()->json([
                    'error' => 'deactivation_conflict',
                    'message' => 'El profesional tiene '.$bookingCount.' reservas futuras por atender. Reubica o cancela sus reservas antes de desactivarlo.',
                    'requires_confirmation' => true,
                    'affects' => [
                        'bookings' => $preflight['bookings'],
                    ],
                ], 409);
            }
        }

        $provider->update($request->validated());

        $provider->refresh();
        $provider->load(['location', 'services']);

        $message = match (true) {
            $isTryingToDeactivate => 'Profesional desactivado.',
            $isActivating => 'Profesional activado.',
            default => 'Profesional actualizado exitosamente',
        };

        return response()->json([
            'data' => new ProviderResource($provider),
            'message' => $message,
        ], 200);
    }

    /**
     * PATCH /api/v1/providers/{id}/roles — replace the business-role set of
     * a provider inside the admin's tenant (BR16-BR21).
     *
     * Replace semantics: the pivot rows for (providerUser, admin.tenant_id)
     * are detached and the given set attached, all-or-nothing. A provider
     * without a linked User gets one auto-created (technical role provider,
     * provider_id tied, verified by creation) unless its email is already
     * owned by another account — then 409 email_collision (never merge).
     */
    public function assignRoles(AssignProviderRolesRequest $request, int $id): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        if ($admin->tenant_id === null) {
            return response()->json([
                'error' => 'onboarding_required',
                'detail' => 'Debes completar la creación de tu negocio antes de asignar roles.',
            ], 409);
        }

        $provider = Provider::with('user')->find($id);

        if (! $provider) {
            return response()->json([
                'error' => 'provider_not_found',
                'detail' => 'El profesional no existe.',
            ], 404);
        }

        if (! $provider->user && User::query()->where('email', $provider->email)->exists()) {
            return response()->json([
                'error' => 'email_collision',
                'detail' => 'El email del profesional ya está asociado a otra cuenta.',
            ], 409);
        }

        $data = DB::transaction(function () use ($provider, $request, $admin): array {
            $user = $this->ensureUser($provider, $admin);

            // Cannot be null here: the email-collision branch above already
            // returned 409, and a provider without a user gets one created.
            if ($user === null) {
                throw new \RuntimeException('Expected a provider user after preflight.');
            }

            $roleIds = Role::query()->whereIn('slug', $request->validated('roles'))->pluck('id');

            $user->roles()->wherePivot('tenant_id', $admin->tenant_id)->detach();

            if ($roleIds->isNotEmpty()) {
                $user->roles()->attach($roleIds->all(), ['tenant_id' => $admin->tenant_id]);
            }

            return [$user, $roleIds];
        });

        [$user, $roleIds] = $data;

        $roles = Role::query()->whereIn('id', $roleIds)->orderBy('id')->get(['slug', 'name']);

        return response()->json([
            'message' => 'Roles del profesional actualizados exitosamente.',
            'data' => [
                'provider_id' => $provider->id,
                'user_id' => $user->id,
                'roles' => $roles->map(fn (Role $role): array => [
                    'slug' => $role->slug,
                    'name' => $role->name,
                ])->values(),
            ],
        ], 200);
    }

    /**
     * Ensure the provider has a linked User, creating one (technical role
     * provider, verified on creation) when missing. Shared by store() and
     * assignRoles() so the user-creation logic stays DRY (S-24).
     *
     * @return User|null the linked/created user, or null when the provider email
     *                   is already owned by another account (never created/merged)
     */
    private function ensureUser(Provider $provider, User $admin): ?User
    {
        // Prefer the loaded relation (assignRoles eager-loads it so it is never
        // marked here); otherwise resolve by provider_id. This avoids triggering
        // a lazy load that would mark `user` as loaded on a freshly created
        // provider in store() and leak `user: null` into the create response.
        $existing = $provider->relationLoaded('user')
            ? $provider->user
            : User::query()->where('provider_id', $provider->id)->first();

        if ($existing) {
            return $existing;
        }

        // The provider email is already owned by another account — never create a
        // duplicate or steal the account (assignRoles 409s; store skips the default).
        if (User::query()->where('email', $provider->email)->exists()) {
            return null;
        }

        $user = User::create([
            'name' => trim($provider->first_name.' '.$provider->last_name),
            'email' => $provider->email,
            'password' => Str::password(32),
            'role' => UserRole::PROVIDER,
            'provider_id' => $provider->id,
            'tenant_id' => $admin->tenant_id,
        ]);

        // Admin-approved creation — otherwise the login gate would strand the
        // professional with no verification path (BR19).
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    /**
     * Apply the default `staff` business role to a freshly created provider,
     * scoped to the admin's tenant. Editable afterward via PATCH /providers/{id}/roles.
     */
    private function assignDefaultStaffRole(Provider $provider, User $admin): void
    {
        // No tenant context → cannot scope a business role. The provider is still
        // created and roles are assigned later via PATCH /providers/{id}/roles.
        if ($admin->tenant_id === null) {
            return;
        }

        DB::transaction(function () use ($provider, $admin): void {
            $user = $this->ensureUser($provider, $admin);

            // Email already owned elsewhere → leave role resolution to assignRoles()
            // (which returns 409 email_collision). Provider creation is unaffected.
            if ($user === null) {
                return;
            }

            $staff = Role::query()->where('slug', BusinessRole::STAFF->value)->first();

            // RoleSeeder may not have run in some environments — skip silently
            // rather than fail provider creation.
            if ($staff === null) {
                return;
            }

            // Only apply the default where the professional holds no business role
            // under this tenant yet, so an existing role set is never disturbed and
            // the user_role unique triple is never violated.
            $hasRole = $user->roles()->wherePivot('tenant_id', $admin->tenant_id)->exists();

            if (! $hasRole) {
                $user->roles()->attach($staff->id, ['tenant_id' => $admin->tenant_id]);
            }
        });
    }
}
