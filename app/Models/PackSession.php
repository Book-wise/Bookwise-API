<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PackSession extends Model
{
    protected $fillable = [
        'client_pack_id', 'booking_id',
        'session_number', 'status', 'attended_at'
    ];

    protected $casts = [
        'attended_at'    => 'datetime',
        'session_number' => 'integer',
    ];

    public function clientPack()
    {
        return $this->belongsTo(ClientPack::class);
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }
}
