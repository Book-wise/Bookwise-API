<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                         => $this->id,
            'start_time'                 => $this->start_time?->toIso8601String(),
            'end_time'                   => $this->end_time?->toIso8601String(),
            'effective_duration_minutes' => $this->effective_duration_minutes,
            'custom_duration_minutes'    => $this->custom_duration_minutes,
            'price'                      => $this->price,
            'notes'                      => $this->notes,
            'wc_order_id'                => $this->wc_order_id,
            'created_at'                 => $this->created_at?->toIso8601String(),
            'client'                     => new ClientResource($this->whenLoaded('client')),
            'service'                    => new ServiceResource($this->whenLoaded('service')),
            'provider'                   => new ProviderResource($this->whenLoaded('provider')),
            'location'                   => new LocationResource($this->whenLoaded('location')),
            'status_id'                  => $this->status_id,
            'status'                     => [
                'id'              => $this->status?->id,
                'name'            => $this->status?->name,
                'color'           => $this->status?->color,
                'is_cancellation' => $this->status?->is_cancellation,
            ],
            'payment_status'             => $this->whenLoaded('sale', fn() => $this->sale->payment_status, null),
            'payment'                    => new PaymentResource($this->whenLoaded('sale')),
            'pack_session'               => $this->whenLoaded('packSession', function () {
                $ps = $this->packSession;
                if (! $ps) return null;
                return [
                    'session_number'  => $ps->session_number,
                    'total_sessions'  => $ps->clientPack?->total_sessions,
                    'client_pack_id'  => $ps->client_pack_id,
                    'status'          => $ps->status,
                ];
            }),
        ];
    }
}
