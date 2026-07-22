<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BookingStoreRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'start_time' => ['required', 'date', 'after:now'],
            'end_time' => ['nullable', 'date', 'after:start_time'],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'service_pack_id' => ['nullable', 'integer', 'exists:service_packs,id'],
            'provider_id' => ['nullable', 'integer', 'exists:providers,id'],
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'status_id' => ['required', 'integer', 'exists:booking_statuses,id'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'duration_minutes' => ['nullable', 'integer', 'min:15', 'max:480'],
            'wc_order_id' => ['nullable', 'integer'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('custom_duration_minutes') && ! $this->has('duration_minutes')) {
            $this->merge([
                'duration_minutes' => $this->input('custom_duration_minutes'),
            ]);
        }
    }
}
