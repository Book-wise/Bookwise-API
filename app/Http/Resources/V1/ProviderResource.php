<?php

namespace App\Http\Resources\V1;

use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProviderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'active' => $this->active,
            'location' => new LocationResource($this->whenLoaded('location')),
            'services' => ServiceResource::collection($this->whenLoaded('services')),
            // Nested caller-tenant business roles (REQ-5). Only present when
            // the relation is eager-loaded (provider index/show); booking
            // endpoints that embed the provider stay byte-compatible (S-11).
            // Roles are never flattened to the top level (BR-C10).
            'user' => $this->whenLoaded('user', function (): ?array {
                if (! $this->user) {
                    return null;
                }

                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'email' => $this->user->email,
                    'roles' => $this->user->roles
                        ->map(fn (Role $role): array => [
                            'slug' => $role->slug,
                            'name' => $role->name,
                        ])
                        ->values(),
                ];
            }),
        ];
    }
}
