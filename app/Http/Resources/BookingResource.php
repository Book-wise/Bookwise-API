<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                      => $this->id,
            'client_id'               => $this->client_id,
            'service_id'              => $this->service_id,
            'provider_id'             => $this->provider_id,
            'location_id'             => $this->location_id,
            'status_id'               => $this->status_id,
            'status'                  => new BookingStatusResource($this->whenLoaded('status')),
            'start_time'              => $this->start_time?->toIso8601String(),
            'end_time'                => $this->end_time?->toIso8601String(),
            'custom_duration_minutes' => $this->custom_duration_minutes,
            'price'                   => (float) $this->price,
            'notes'                   => $this->notes,
            'wc_order_id'             => $this->wc_order_id,
            'created_at'              => $this->created_at?->toIso8601String(),
            'updated_at'              => $this->updated_at?->toIso8601String(),
        ];
    }
}
