<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    protected $fillable = [
        'booking_id', 'wc_order_id',
        'total', 'payment_method', 'paid_at'
    ];

    protected $casts = [
        'total'   => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }
}
