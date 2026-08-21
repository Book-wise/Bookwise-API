<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PackSession extends Model
{
    protected $fillable = [
        'client_pack_id', 'booking_id',
        'session_number', 'status', 'attended_at',
        'price', 'notes',
    ];

    protected $casts = [
        'attended_at' => 'datetime',
        'session_number' => 'integer',
        'price' => 'decimal:2',
    ];

    public function clientPack()
    {
        return $this->belongsTo(ClientPack::class);
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    // Default: service.price — set price on the session to override per session
    public function getEffectivePriceAttribute(): float
    {
        if ($this->price !== null) {
            return (float) $this->price;
        }

        return (float) ($this->clientPack?->servicePack?->service?->price ?? 0);
    }
}
