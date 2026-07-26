<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'address' => $this->address,
            'city' => $this->city,
            'codigo_postal' => $this->codigo_postal,
            'timezone' => $this->timezone,
            'region_id' => $this->region_id,
            'region' => $this->relationLoaded('region') && $this->region ? [
                'id' => $this->region->id,
                'name' => $this->region->name,
                'timezone' => $this->region->timezone,
            ] : null,
            'comuna_id' => $this->comuna_id,
            'comuna' => $this->relationLoaded('comuna') && $this->comuna ? [
                'id' => $this->comuna->id,
                'name' => $this->comuna->name,
            ] : null,
            'opening_time' => $this->opening_time,
            'closing_time' => $this->closing_time,
            'active' => $this->active,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
