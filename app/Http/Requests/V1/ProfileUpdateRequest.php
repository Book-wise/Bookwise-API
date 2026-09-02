<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Actualización de perfil del usuario autenticado.
 *
 * Por contrato solo el teléfono es editable: email y nombre quedan read-only
 * (no se incluyen acá ni se tocan en el controlador).
 */
class ProfileUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Mismo criterio que el registro (RegisterRequest): no hay formato
            // E.164 estricto en el proyecto. Si se quiere E.164 hay que cambiarlo
            // en ambos lugares (register + perfil) como cambio aparte.
            'phone' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.required' => 'El teléfono es obligatorio.',
            'phone.string' => 'El teléfono debe ser un texto válido.',
            'phone.max' => 'El teléfono no puede superar los 255 caracteres.',
        ];
    }
}
