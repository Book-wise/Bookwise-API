<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BusinessRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\StoreBusinessRequest;
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
}
