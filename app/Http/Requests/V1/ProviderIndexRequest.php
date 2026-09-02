<?php

namespace App\Http\Requests\V1;

use App\Enums\BusinessRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /api/v1/providers — optional `roles[]` attendance filter.
 *
 * `sometimes` (not `present`) so an absent or explicitly empty array is
 * accepted and treated as "no filter" (REQ-1/S-5). Each element must be one
 * of the BusinessRole slugs and the array must be distinct and bounded by
 * the size of the enum (duplicates or unknown slugs → 422, BR17 parity).
 */
class ProviderIndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'roles' => ['sometimes', 'array', 'max:'.count(BusinessRole::cases())],
            'roles.*' => ['distinct', Rule::in(BusinessRole::values())],
        ];
    }

    public function messages(): array
    {
        return [
            'roles.array' => 'El campo roles debe ser un arreglo.',
            'roles.max' => 'El campo roles no debe contener más de :max elementos.',
            'roles.*.distinct' => 'El campo roles no debe contener valores duplicados.',
            'roles.*.in' => 'El rol :input no es válido.',
        ];
    }
}
