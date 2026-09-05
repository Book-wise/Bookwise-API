<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Asigna o des-asigna un usuario como admin_local de un negocio (tenant).
 *
 * Usado por POST y DELETE /v1/businesses/{id}/assign-admin-local. En ambos
 * casos el body es { user_id }; para des-asignar se borra el pivot scoped.
 */
class AssignAdminLocalRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')],
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.required' => 'El usuario es obligatorio.',
            'user_id.integer' => 'El usuario debe ser un id válido.',
            'user_id.exists' => 'El usuario no existe.',
        ];
    }
}
