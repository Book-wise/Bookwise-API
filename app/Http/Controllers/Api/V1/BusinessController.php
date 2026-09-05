<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BusinessRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\StoreBusinessRequest;
use App\Http\Requests\V1\UpdateBusinessRequest;
use App\Http\Resources\V1\BusinessResource;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class BusinessController extends Controller
{
    /**
     * GET /v1/businesses — the authenticated user's business profile.
     *
     * Returns {data: null} before onboarding, the BusinessResource after.
     */
    public function index(): JsonResponse
    {
        $tenant = auth()->user()->tenant;

        return response()->json([
            'data' => $tenant ? new BusinessResource($tenant) : null,
        ]);
    }

    /**
     * POST /v1/businesses — create the business profile (onboarding).
     *
     * Requires a verified email (BR9); a user can only have one business
     * (BR11). All-or-nothing transaction (BR22): tenant row + user->tenant_id
     * association + admin_general business-role pivot under the new tenant.
     */
    public function store(StoreBusinessRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->isVerified()) {
            return response()->json(['error' => 'email_not_verified'], 403);
        }

        if ($user->tenant) {
            return response()->json(['error' => 'business_already_exists'], 409);
        }

        $data = $request->validated();

        $tenant = DB::transaction(function () use ($user, $data): Tenant {
            $tenant = Tenant::create([
                'business_name' => $data['name'],
                'business_rut' => $data['rut'],
                'business_email' => $data['email'],
                'business_address' => $data['address'],
                'business_phone' => $data['phone'],
                'business_plan' => $data['plan'] ?? 'starter',
            ]);

            $user->tenant()->associate($tenant)->save();

            $adminRole = Role::where('slug', BusinessRole::ADMIN_GENERAL->value)->firstOrFail();

            $user->roles()->attach($adminRole->id, ['tenant_id' => $tenant->id]);

            return $tenant;
        });

        return response()->json([
            'data' => new BusinessResource($tenant),
            'message' => 'Tu negocio fue creado correctamente.',
        ], 201);
    }

    /**
     * PATCH /v1/businesses/{id} — edita la info del negocio (RUT inmutable).
     * Solo admin_general (org) o el admin del propio negocio.
     */
    public function update(UpdateBusinessRequest $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $tenant = Tenant::findOrFail($id);

        // Solo admin_general (org) o admin_local del propio negocio editan.
        if (! $user->isAdminGeneral() && ! ($user->isAdminLocal() && $user->tenant_id === $tenant->id)) {
            abort(403, 'No autorizado para editar este negocio.');
        }

        $data = $request->validated();

        $tenant->update([
            'business_name' => $data['name'],
            'business_email' => $data['email'] ?? null,
            'business_address' => $data['address'] ?? null,
            'business_phone' => $data['phone'] ?? null,
            'business_plan' => $data['plan'],
        ]);

        return response()->json([
            'data' => new BusinessResource($tenant),
        ]);
    }
}
