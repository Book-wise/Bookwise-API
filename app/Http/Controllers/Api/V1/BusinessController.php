<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BusinessRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\AssignAdminLocalRequest;
use App\Http\Requests\V1\LogoUploadRequest;
use App\Http\Requests\V1\StoreBusinessRequest;
use App\Http\Requests\V1\UpdateBusinessRequest;
use App\Http\Resources\V1\BusinessResource;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\LogoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BusinessController extends Controller
{
    public function __construct(
        private readonly LogoService $logoService,
    ) {}

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
     * POST /v1/businesses — create a business (tenant).
     *
     * Requires a verified email (BR9). Rules:
     *  - No business yet (onboarding): create + associate as the user's active
     *    tenant (BR11), attach admin_general under it.
     *  - Already has a business AND is admin_general: create ANOTHER tenant,
     *    attach admin_general under it, but DO NOT change the active tenant_id.
     *    This is the admin-general multi-tenant path.
     *  - Already has a business but NOT admin_general: 409 (blocked, BR11).
     *
     * All-or-nothing transaction (BR22): tenant row + user->tenant_id
     * association (first business only) + admin_general pivot under the tenant.
     */
    public function store(StoreBusinessRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->isVerified()) {
            return response()->json(['error' => 'email_not_verified'], 403);
        }

        $hasBusiness = $user->tenant_id !== null;

        // Non-admin users may not create an additional business once on-boarded.
        if ($hasBusiness && ! $user->isAdminGeneral()) {
            return response()->json(['error' => 'business_already_exists'], 409);
        }

        $data = $request->validated();

        $tenant = DB::transaction(function () use ($user, $data, $hasBusiness): Tenant {
            $tenant = Tenant::create([
                'business_name' => $data['name'],
                'business_rut' => $data['rut'],
                'business_email' => $data['email'],
                'business_address' => $data['address'],
                'business_phone' => $data['phone'],
                'business_plan' => $data['plan'] ?? 'starter',
            ]);

            // First business → becomes the active tenant (onboarding).
            if (! $hasBusiness) {
                $user->tenant()->associate($tenant)->save();
            }

            $adminRole = Role::where('slug', BusinessRole::ADMIN_GENERAL->value)->firstOrFail();

            $user->roles()->attach($adminRole->id, ['tenant_id' => $tenant->id]);

            return $tenant;
        });

        // Logo OPCIONAL, procesado FUERA de la transacción: si falla (sin GD o
        // decode fallido) el negocio se crea igual con logo_url null + warning.
        // Nunca abortamos la creación por el logo. Usamos ->file() porque los
        // archivos NO viajan en $request->validated().
        $warnings = [];
        if ($request->hasFile('logo')) {
            try {
                $url = $this->logoService->store($request->file('logo'), $tenant);
                $tenant->update(['business_logo_url' => $url]);
            } catch (RuntimeException $e) {
                report($e);
                $warnings[] = 'logo_processing_failed';
            }
        }

        return response()->json([
            'data' => new BusinessResource($tenant->fresh()),
            'message' => 'Tu negocio fue creado correctamente.',
            'warnings' => $warnings,
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
        if (! $user->canManageTenant($tenant->id)) {
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

    /**
     * POST /v1/businesses/{id}/assign-admin-local — asigna un usuario como
     * admin_local de un negocio. Solo admin_general. Idempotente: si el pivot
     * ya existe (unique user_id+tenant_id+role_id) no duplica.
     */
    public function assignAdminLocal(AssignAdminLocalRequest $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->isAdminGeneral()) {
            abort(403, 'Solo el admin general puede asignar administradores locales.');
        }

        $tenant = Tenant::findOrFail($id);
        $targetUserId = $request->validated('user_id');

        // El target también debe existir y querer un rol de negocio en este tenant.
        User::findOrFail($targetUserId);

        $role = Role::where('slug', BusinessRole::ADMIN_LOCAL->value)->firstOrFail();

        RoleAssignment::firstOrCreate([
            'user_id' => $targetUserId,
            'tenant_id' => $tenant->id,
            'role_id' => $role->id,
        ]);

        return response()->json([
            'message' => 'Administrador local asignado.',
            'data' => [
                'tenant_id' => $tenant->id,
                'user_id' => $targetUserId,
                'role' => BusinessRole::ADMIN_LOCAL->value,
            ],
        ]);
    }

    /**
     * DELETE /v1/businesses/{id}/assign-admin-local — quita a un usuario como
     * admin_local de un negocio. Solo admin_general. Idempotente: si no estaba
     * asignado, no falla.
     */
    public function unassignAdminLocal(AssignAdminLocalRequest $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->isAdminGeneral()) {
            abort(403, 'Solo el admin general puede desasignar administradores locales.');
        }

        $tenant = Tenant::findOrFail($id);
        $targetUserId = $request->validated('user_id');

        $role = Role::where('slug', BusinessRole::ADMIN_LOCAL->value)->firstOrFail();

        RoleAssignment::where('user_id', $targetUserId)
            ->where('tenant_id', $tenant->id)
            ->where('role_id', $role->id)
            ->delete();

        return response()->json([
            'message' => 'Administrador local desasignado.',
            'data' => [
                'tenant_id' => $tenant->id,
                'user_id' => $targetUserId,
                'role' => BusinessRole::ADMIN_LOCAL->value,
            ],
        ]);
    }

    /**
     * POST /v1/businesses/{id}/logo — sube el logo de UN negocio concreto
     * (no el del tenant activo). Autorizado si el usuario puede gestionarlo.
     * El logo aparece en recibos y comunicaciones por email.
     */
    public function uploadLogo(LogoUploadRequest $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $tenant = Tenant::findOrFail($id);

        if (! $user->canManageTenant($tenant->id)) {
            abort(403, 'No autorizado para subir el logo de este negocio.');
        }

        try {
            $url = $this->logoService->store($request->file('logo'), $tenant);
        } catch (RuntimeException $e) {
            report($e);

            return response()->json([
                'error' => 'logo_processing_failed',
                'detail' => 'No se pudo procesar el logo.',
            ], 501);
        }

        $tenant->update(['business_logo_url' => $url]);

        return response()->json([
            'data' => new BusinessResource($tenant->fresh()),
        ]);
    }

    /**
     * DELETE /v1/businesses/{id}/logo — quita el logo de UN negocio concreto.
     */
    public function removeLogo(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $tenant = Tenant::findOrFail($id);

        if (! $user->canManageTenant($tenant->id)) {
            abort(403, 'No autorizado para quitar el logo de este negocio.');
        }

        $this->logoService->remove($tenant);

        return response()->json([
            'data' => new BusinessResource($tenant->fresh()),
        ]);
    }
}
