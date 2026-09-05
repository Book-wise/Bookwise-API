<?php

namespace App\Http\Requests\V1;

use App\Rules\ChileanRutRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBusinessRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'rut' => ['required', 'string', new ChileanRutRule, Rule::unique('tenants', 'business_rut')],
            'email' => ['required', 'email', 'max:255'],
            'address' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'plan' => ['sometimes', Rule::in(['starter', 'professional', 'enterprise'])],
            // Logo OPCIONAL del negocio (aparece en recibos/email). Se procesa
            // fuera de la transacción: si falla, el negocio se crea igual.
            'logo' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre del negocio es obligatorio.',
            'name.string' => 'El nombre del negocio debe ser un texto válido.',
            'name.max' => 'El nombre del negocio no puede superar los 255 caracteres.',
            'rut.required' => 'El RUT del negocio es obligatorio.',
            'rut.string' => 'El RUT del negocio debe ser un texto válido.',
            'rut.unique' => 'Ya existe un negocio registrado con ese RUT.',
            'email.required' => 'El email del negocio es obligatorio.',
            'email.email' => 'El email del negocio no tiene un formato válido.',
            'email.max' => 'El email del negocio no puede superar los 255 caracteres.',
            'address.required' => 'La dirección es obligatoria.',
            'address.string' => 'La dirección debe ser un texto válido.',
            'address.max' => 'La dirección no puede superar los 255 caracteres.',
            'phone.required' => 'El teléfono del negocio es obligatorio.',
            'phone.string' => 'El teléfono del negocio debe ser un texto válido.',
            'phone.max' => 'El teléfono del negocio no puede superar los 50 caracteres.',
            'plan.in' => 'El plan seleccionado no es válido.',
            'logo.image' => 'El logo debe ser una imagen.',
            'logo.mimes' => 'El logo debe ser JPG, PNG o WebP.',
            'logo.max' => 'El logo no puede superar los 2 MB.',
        ];
    }
}
