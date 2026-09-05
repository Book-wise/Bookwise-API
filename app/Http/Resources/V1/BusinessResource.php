<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BusinessResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->business_name,
            'rut' => $this->business_rut,
            'email' => $this->business_email,
            'address' => $this->business_address,
            'phone' => $this->business_phone,
            'plan' => $this->business_plan,
            'logo_url' => $this->business_logo_url,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
