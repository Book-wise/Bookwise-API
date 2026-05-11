<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomAttributeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->template?->name ?? $this->name,
            'field_type' => $this->template?->field_type ?? $this->field_type,
            'options'    => $this->template?->options ?? $this->options,
            'required'   => $this->template?->required ?? $this->required,
            'value'      => $this->when(isset($this->value), $this->value),
        ];
    }
}
