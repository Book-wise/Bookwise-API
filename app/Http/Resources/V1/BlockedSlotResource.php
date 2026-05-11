<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BlockedSlotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'start_time'      => $this->start_time?->toIso8601String(),
            'end_time'        => $this->end_time?->toIso8601String(),
            'reason'          => $this->reason,
            'provider_id'     => $this->provider_id,
            'location_id'     => $this->location_id,
            'repeat_group_id' => $this->repeat_group_id,
        ];
    }
}
