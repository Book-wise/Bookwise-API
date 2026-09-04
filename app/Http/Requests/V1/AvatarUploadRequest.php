<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Subida de avatar del usuario autenticado.
 *
 * Mismo criterio que el logo del negocio (LogoUploadRequest): se acepta una
 * imagen y se genera un thumbnail optimizado. El archivo original nunca se
 * persiste.
 */
class AvatarUploadRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'avatar' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:2048'],
        ];
    }
}
