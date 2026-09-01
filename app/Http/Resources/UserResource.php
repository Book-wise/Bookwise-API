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
            'role' => $this->role,
            'provider_id' => $this->provider_id,
            'tenant_id' => $this->tenant_id,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            // Onboarding is complete once the user owns a business (R10.2).
            'onboarding_complete' => $this->tenant_id !== null,
            'business' => new BusinessResource($this->whenLoaded('tenant')),
        ];
    }
}
