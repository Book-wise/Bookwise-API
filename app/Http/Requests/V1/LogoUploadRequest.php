<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class LogoUploadRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'logo' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:2048'],
        ];
    }
}
