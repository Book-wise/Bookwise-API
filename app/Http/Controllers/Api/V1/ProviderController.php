<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProviderStoreRequest;
use App\Http\Requests\ProviderUpdateRequest;
use App\Http\Requests\V1\AssignProviderRolesRequest;
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
    public function index(Request $request): JsonResponse
    {
        $providers = Provider::query()
            ->with(['location', 'services'])
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

    public function show(int $id): JsonResponse
    {
        $provider = Provider::with(['location', 'services'])->findOrFail($id);

        return response()->json(['data' => new ProviderResource($provider)]);
    }

    public function store(ProviderStoreRequest $request): JsonResponse
    {
        $provider = Provider::create($request->validated());

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
            $user = $provider->user;

            if (! $user) {
                $user = User::create([
                    'name' => trim($provider->first_name.' '.$provider->last_name),
                    'email' => $provider->email,
                    'password' => Str::password(32),
                    'role' => UserRole::PROVIDER,
                    'provider_id' => $provider->id,
                    'tenant_id' => $admin->tenant_id,
                ]);

                // Admin-approved creation — otherwise the login gate would
                // strand the professional with no verification path (BR19).
                $user->forceFill(['email_verified_at' => now()])->save();
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
}
