<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'rut' => $this->rut,
            'rut_missing' => empty($this->rut),
            'gender' => $this->gender,
            'wc_customer_id' => $this->wc_customer_id,
            'address' => $this->address,
            'notes' => $this->notes,
            'active' => $this->active,
            'notifications_enabled' => (bool) $this->notifications_enabled,
            'notification_prefs' => [
                'email_new_booking' => (bool) $this->email_new_booking,
                'email_booking_confirmation' => (bool) $this->email_booking_confirmation,
                'email_booking_cancellation' => (bool) $this->email_booking_cancellation,
                'whatsapp_reminder' => (bool) $this->whatsapp_reminder,
                'whatsapp_cancellation_confirmation' => (bool) $this->whatsapp_cancellation_confirmation,
            ],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
