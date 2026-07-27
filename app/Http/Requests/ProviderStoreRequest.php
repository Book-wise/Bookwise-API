<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProviderStoreRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('providers')],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'first_name.required' => 'El nombre del profesional es obligatorio.',
            'last_name.required' => 'El apellido del profesional es obligatorio.',
            'email.required' => 'El email es obligatorio.',
            'email.email' => 'El email no tiene un formato válido.',
            'email.unique' => 'Ya existe un profesional con ese email.',
            'location_id.required' => 'La sucursal es obligatoria.',
            'location_id.exists' => 'La sucursal seleccionada no es válida.',
        ];
    }
}
