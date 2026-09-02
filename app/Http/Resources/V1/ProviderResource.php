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
            // Top-level caller-tenant business roles, shaped for the frontend
            // Role model (`{ id, name, slug }`). Present only when `user` is
            // eager-loaded (provider index/show); booking endpoints embed the
            // provider WITHOUT loading `user`, so this key stays absent and the
            // payload remains byte-compatible (S-11). A user-less provider
            // surfaces an empty array (no business roles).
            'roles' => $this->when($this->relationLoaded('user'), fn (): array => $this->user
                ? $this->user->roles
                    ->map(fn (Role $role): array => [
                        'id' => $role->id,
                        'slug' => $role->slug,
                        'name' => $role->name,
                    ])
                    ->values()
                    ->all()
                : []),
            // Nested caller-tenant business roles (REQ-5). Only present when
            // the relation is eager-loaded (provider index/show); booking
            // endpoints that embed the provider stay byte-compatible (S-11).
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
