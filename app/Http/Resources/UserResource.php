<?php

namespace App\Http\Resources;

use App\Http\Resources\V1\BusinessResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'avatar_url' => $this->avatar_url,
            'role' => $this->role,
            'provider_id' => $this->provider_id,
            'tenant_id' => $this->tenant_id,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            // Onboarding is complete once the user owns a business (R10.2).
            'onboarding_complete' => $this->tenant_id !== null,
            'business' => new BusinessResource($this->whenLoaded('tenant')),
            'businesses' => $this->businessesList(),
            // Roles de NEGOCIO (distintos del rol técnico `role`). El frontend
            // los usa para gatear acciones de gestión multi-tenant.
            'is_admin_general' => $this->isAdminGeneral(),
            'is_admin_local' => $this->isAdminLocal(),
        ];
    }

    /**
     * Negocios a los que el usuario puede alternar (selector multi-tenant).
     *
     * Son los tenants donde el usuario tiene UN ROL DE NEGOCIO asignado
     * (pivot user_role), NO todos los tenants del sistema. Así un admin
     * general ve solo sus negocios, un admin local solo el suyo, y nadie
     * ve tenants ajenos a sus relaciones.
     */
    private function businessesList(): array
    {
        $businesses = $this->businesses()
            ->orderBy('business_name')
            ->get()
            ->map(fn ($tenant) => new BusinessResource($tenant))
            ->all();

        // Compatibilidad: si por cualquier motivo el usuario aún no tiene pivots
        // de rol de negocio pero sí un tenant activo, lo exponemos igual (casos
        // seed previos al multi-tenant).
        if ($businesses === [] && $this->tenant_id) {
            return [new BusinessResource($this->tenant)];
        }

        return array_values(array_filter($businesses));
    }
}
