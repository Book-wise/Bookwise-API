<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProviderUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        $providerId = $this->route('id');

        return [
            'first_name' => ['sometimes', 'string', 'max:255'],
            'last_name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('providers')->ignore($providerId)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'location_id' => ['sometimes', 'integer', 'exists:locations,id'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.email' => 'El email no tiene un formato válido.',
            'email.unique' => 'Ya existe un profesional con ese email.',
            'location_id.exists' => 'La sucursal seleccionada no es válida.',
        ];
    }
}
