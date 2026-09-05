<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property object $provider
 * @property object $location
 * @property string $start_time
 * @property string $end_time
 */
class AgentAvailabilitySlotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'provider' => [
                'id' => $this['provider']->id,
                'first_name' => $this['provider']->first_name,
                'last_name' => $this['provider']->last_name,
            ],
            'location' => [
                'id' => $this['location']->id,
                'name' => $this['location']->name,
            ],
            'start_time' => $this['start_time'],
            'end_time' => $this['end_time'],
        ];
    }
}
