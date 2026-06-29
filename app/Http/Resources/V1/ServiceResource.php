<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'duration_minutes' => $this->duration_minutes,
            'slot_interval_minutes' => $this->slot_interval_minutes,
            'min_duration_minutes' => $this->min_duration_minutes,
            'max_duration_minutes' => $this->max_duration_minutes,
            'price' => $this->price,
            'active' => $this->active,
        ];
    }
}
