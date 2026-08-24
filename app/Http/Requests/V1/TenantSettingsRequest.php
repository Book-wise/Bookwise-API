<?php

namespace App\Http\Requests\V1;

use App\Rules\ChileanRutRule;
use Illuminate\Foundation\Http\FormRequest;

class TenantSettingsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'business_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'business_rut' => ['sometimes', 'nullable', 'string', 'max:255', new ChileanRutRule],
        ];
    }
}
