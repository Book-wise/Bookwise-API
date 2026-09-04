<?php

namespace App\Http\Resources;

use App\Enums\UserRole;
use App\Http\Resources\V1\BusinessResource;
use App\Models\Tenant;
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
        ];
    }

    /**
     * Negocios a los que el usuario puede alternar (para el selector multi-tenant).
     * Admin general (rol de negocio) ve todos; admin local / provider / staff,
     * solo el suyo.
     */
    private function businessesList(): array
    {
        if ($this->role !== UserRole::ADMIN || ! $this->isAdminGeneral()) {
            return $this->tenant_id ? [new BusinessResource($this->tenant)] : [];
        }

        return Tenant::orderBy('business_name')
            ->get()
            ->map(fn ($tenant) => new BusinessResource($tenant))
            ->all();
    }
}
