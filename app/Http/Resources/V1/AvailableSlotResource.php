<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property string $start
 * @property string $end
 * @property int $duration_minutes
 * @property int $provider_id
 */
class AvailableSlotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'start' => $this['start'],
            'end' => $this['end'],
            'duration_minutes' => $this['duration_minutes'],
            'provider_id' => $this['provider_id'],
        ];
    }
}
