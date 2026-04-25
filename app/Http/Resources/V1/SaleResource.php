<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'wc_order_id'    => $this->wc_order_id,
            'total'          => $this->total,
            'payment_method' => $this->payment_method,
            'paid_at'        => $this->paid_at?->toIso8601String(),
            'created_at'     => $this->created_at?->toIso8601String(),
            'booking'        => new BookingResource($this->whenLoaded('booking')),
        ];
    }
}
