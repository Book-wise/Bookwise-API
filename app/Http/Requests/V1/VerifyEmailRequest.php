<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class VerifyEmailRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'token.required' => 'El token es obligatorio.',
            'token.string' => 'El token debe ser un texto válido.',
        ];
    }
}
