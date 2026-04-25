<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProviderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'first_name' => $this->first_name,
            'last_name'  => $this->last_name,
            'email'      => $this->email,
            'phone'      => $this->phone,
            'active'     => $this->active,
            'locations'  => LocationResource::collection($this->whenLoaded('locations')),
            'services'   => ServiceResource::collection($this->whenLoaded('services')),
        ];
    }
}
