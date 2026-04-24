<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomAttributeTemplate extends Model
{
    protected $fillable = [
        'name', 'field_type', 'options', 'required'
    ];

    protected $casts = [
        'options'  => 'array',
        'required' => 'boolean',
    ];

    public function clientAttributes()
    {
        return $this->hasMany(ClientCustomAttribute::class);
    }
}
