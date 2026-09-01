<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class TenantSettingsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'business_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            // BR12/D9: business_rut and business_email are immutable once
            // set during onboarding — any attempt to change them yields 422.
            'business_rut' => ['prohibited'],
            'business_email' => ['prohibited'],
        ];
    }
}
