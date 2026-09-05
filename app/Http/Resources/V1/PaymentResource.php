<?php

namespace App\Http\Resources\V1;

use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Sale $resource
 */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'booking_id' => $this->booking_id,
            'total_amount' => (float) $this->total,
            'paid_amount' => (float) $this->paid_amount,
            'remaining_amount' => $this->remaining_amount,
            'status' => $this->payment_status,
        ];
    }
}
