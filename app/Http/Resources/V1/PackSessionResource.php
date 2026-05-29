<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PackSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'session_number'  => $this->session_number,
            'status'          => $this->status,
            'price'           => $this->price,
            'effective_price' => $this->effective_price,
            'notes'           => $this->notes,
            'attended_at'     => $this->attended_at?->toIso8601String(),
            'booking_id'      => $this->booking_id,
            'booking'         => $this->whenLoaded('booking', function () {
                $b = $this->booking;
                if (! $b) return null;
                return [
                    'id'         => $b->id,
                    'start_time' => $b->start_time?->toIso8601String(),
                    'end_time'   => $b->end_time?->toIso8601String(),
                    'price'      => $b->price,
                    'provider'   => $b->provider ? [
                        'id'         => $b->provider->id,
                        'first_name' => $b->provider->first_name,
                        'last_name'  => $b->provider->last_name,
                    ] : null,
                    'location'   => $b->location ? [
                        'id'   => $b->location->id,
                        'name' => $b->location->name,
                    ] : null,
                    'status'     => $b->status ? [
                        'id'    => $b->status->id,
                        'name'  => $b->status->name,
                        'color' => $b->status->color,
                    ] : null,
                ];
            }),
        ];
    }
}
