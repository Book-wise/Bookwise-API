<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientCustomAttribute extends Model
{
    protected $fillable = [
        'client_id', 'custom_attribute_template_id', 'value'
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function template()
    {
        return $this->belongsTo(CustomAttributeTemplate::class, 'custom_attribute_template_id');
    }
}
