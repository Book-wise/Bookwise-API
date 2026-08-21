<?php

namespace App\Http\Requests;

use App\Models\Comuna;
use App\Models\Location;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LocationUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        $locationId = $this->route('id');

        return [
            'name' => ['sometimes', 'string', 'max:255', Rule::unique('locations')->ignore($locationId)],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'region_id' => ['sometimes', 'integer', 'exists:regions,id'],
            'comuna_id' => ['sometimes', 'nullable', 'integer', 'exists:comunas,id'],
            'opening_time' => ['sometimes', 'nullable', 'string', 'date_format:H:i:s'],
            'closing_time' => ['sometimes', 'nullable', 'string', 'date_format:H:i:s'],
            'active' => ['sometimes', 'boolean'],
            'force' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'Ya existe una sucursal con ese nombre.',
            'name.max' => 'El nombre no puede superar los 255 caracteres.',
            'region_id.exists' => 'La región seleccionada no es válida.',
            'comuna_id.exists' => 'La comuna seleccionada no es válida.',
            'opening_time.date_format' => 'El formato de hora de apertura debe ser HH:MM:SS.',
            'closing_time.date_format' => 'El formato de hora de cierre debe ser HH:MM:SS.',
        ];
    }

    protected function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->has('region_id') || $validator->errors()->has('comuna_id')) {
                return;
            }

            $comunaId = $this->input('comuna_id');
            if ($comunaId === null || $comunaId === '') {
                return;
            }

            $location = Location::findOrFail($this->route('id'));
            $regionId = $this->input('region_id', $location->region_id);
            $comuna = Comuna::find($comunaId);

            if (! $comuna || (int) $comuna->region_id !== (int) $regionId) {
                $validator->errors()->add('comuna_id', 'La comuna no pertenece a la región seleccionada.');
            }
        });
    }
}
