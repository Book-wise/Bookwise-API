<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'wc_order_id'      => $this->wc_order_id,
            'total'            => $this->total,
            'paid_amount'      => $this->paid_amount,
            'remaining_amount' => $this->remaining_amount,
            'payment_status'   => $this->payment_status,
            'payment_method'   => $this->payment_method,
            'paid_at'          => $this->paid_at?->toIso8601String(),
            'created_at'       => $this->created_at?->toIso8601String(),
            'client'           => new ClientResource($this->whenLoaded('client')),
            'booking'          => new BookingResource($this->whenLoaded('booking')),
            'client_pack'      => $this->whenLoaded('clientPack', function () {
                $cp = $this->clientPack;
                if (! $cp) return null;
                return [
                    'id'             => $cp->id,
                    'total_sessions' => $cp->total_sessions,
                    'used_sessions'  => $cp->used_sessions,
                    'status'         => $cp->status,
                    'service_pack'   => $cp->servicePack ? [
                        'id'    => $cp->servicePack->id,
                        'name'  => $cp->servicePack->name,
                        'price' => $cp->servicePack->price,
                        'service' => $cp->servicePack->service ? [
                            'id'    => $cp->servicePack->service->id,
                            'name'  => $cp->servicePack->service->name,
                            'price' => $cp->servicePack->service->price,
                        ] : null,
                    ] : null,
                    'sessions' => $cp->relationLoaded('sessions')
                        ? PackSessionResource::collection($cp->sessions)
                        : [],
                ];
            }),
            'transactions'     => SaleTransactionResource::collection($this->whenLoaded('transactions')),
        ];
    }
}
