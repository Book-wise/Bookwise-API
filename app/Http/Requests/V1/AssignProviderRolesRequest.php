<?php

namespace App\Http\Requests\V1;

use App\Enums\BusinessRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/v1/providers/{id}/roles — the business-role set to assign.
 *
 * `present` (not `required`) so an empty array is accepted: an empty set
 * clears all roles (BR17/S23). Each element must be one of the six
 * BusinessRole slugs and the array must be distinct (duplicates → 422).
 */
class AssignProviderRolesRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'roles' => ['present', 'array'],
            'roles.*' => ['distinct', Rule::in(BusinessRole::values())],
        ];
    }

    public function messages(): array
    {
        return [
            'roles.present' => 'El campo roles es obligatorio.',
            'roles.array' => 'El campo roles debe ser un arreglo.',
            'roles.*.distinct' => 'El campo roles no debe contener valores duplicados.',
            'roles.*.in' => 'El rol :input no es válido.',
        ];
    }
}
