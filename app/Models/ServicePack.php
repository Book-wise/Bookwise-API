<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ServicePack extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'service_id', 'name', 'total_sessions', 'price', 'active'
    ];

    protected $casts = [
        'active'         => 'boolean',
        'price'          => 'decimal:2',
        'total_sessions' => 'integer',
    ];

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function clientPacks()
    {
        return $this->hasMany(ClientPack::class);
    }
}
