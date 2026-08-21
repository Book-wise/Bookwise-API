<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BookingUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'start_time' => ['sometimes', 'date', 'after:now'],
            'end_time' => ['sometimes', 'date', 'after:start_time'],
            'status_id' => ['sometimes', 'integer', 'exists:booking_statuses,id'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'provider_id' => ['sometimes', 'nullable', 'integer', 'exists:providers,id'],
        ];
    }
}
