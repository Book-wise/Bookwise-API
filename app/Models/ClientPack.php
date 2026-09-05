<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientPack extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'client_id', 'service_pack_id', 'wc_order_id',
        'total_sessions', 'used_sessions', 'status',
    ];

    protected $casts = [
        'total_sessions' => 'integer',
        'used_sessions' => 'integer',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function servicePack()
    {
        return $this->belongsTo(ServicePack::class);
    }

    public function sessions()
    {
        return $this->hasMany(PackSession::class);
    }

    public function getRemainingSessionsAttribute(): int
    {
        return $this->total_sessions - $this->used_sessions;
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
