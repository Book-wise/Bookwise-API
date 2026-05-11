<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'       => $this->id,
            'name'     => $this->name,
            'address'  => $this->address,
            'city'     => $this->city,
            'timezone' => $this->timezone,
            'active'   => $this->active,
        ];
    }
}
