<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Validator;

class PasswordChangeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    /**
     * Post-validación con el usuario autenticado (viene del token, nunca del body):
     *  - la contraseña actual debe coincidir con la del usuario;
     *  - la nueva no puede ser igual a la actual.
     *
     * La comprobación "distinta a la actual" SOLO corre cuando la actual ya fue
     * verificada. Si corriera con current_password inválida, la respuesta
     * revelaría cuándo una adivinanza de `password` coincide con la clave real
     * (oráculo de fuerza bruta).
     */
    public function after(): array
    {
        $user = $this->user();

        return [
            function (Validator $validator) use ($user): void {
                $currentIsCorrect = $this->filled('current_password')
                    && Hash::check((string) $this->input('current_password'), $user->password);

                if (! $currentIsCorrect) {
                    if ($this->filled('current_password')) {
                        $validator->errors()->add('current_password', 'La contraseña actual no es correcta.');
                    }

                    return;
                }

                if ($this->filled('password')
                    && Hash::check((string) $this->input('password'), $user->password)) {
                    $validator->errors()->add('password', 'La nueva contraseña debe ser distinta a la actual.');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'current_password.required' => 'La contraseña actual es obligatoria.',
            'current_password.string' => 'La contraseña actual debe ser un texto válido.',
            'password.required' => 'La nueva contraseña es obligatoria.',
            'password.string' => 'La nueva contraseña debe ser un texto válido.',
            'password.min' => 'La nueva contraseña debe tener al menos 8 caracteres.',
            'password.confirmed' => 'La confirmación de contraseña no coincide.',
        ];
    }
}
