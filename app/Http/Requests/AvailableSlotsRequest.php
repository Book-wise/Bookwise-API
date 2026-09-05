<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AvailableSlotsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'provider_id' => ['nullable', 'integer', 'exists:providers,id'],
            'duration_minutes' => [
                'nullable', 'integer',
                'min:'.config('booking.min_duration_minutes', 15),
                'max:'.config('booking.max_duration_minutes', 480),
                function ($attribute, $value, $fail) {
                    if ($value !== null && $value % 15 !== 0) {
                        $fail('The duration must be a multiple of 15 minutes.');
                    }
                },
            ],
            'slot_interval' => ['nullable', 'integer', 'min:5', 'max:480'],
        ];
    }
}
