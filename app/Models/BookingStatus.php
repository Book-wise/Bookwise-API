<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingStatus extends Model
{
    protected $fillable = [
        'name', 'color', 'is_cancellation', 'is_finalized',
    ];

    protected $casts = [
        'is_cancellation' => 'boolean',
        'is_finalized' => 'boolean',
    ];

    public function bookings()
    {
        return $this->hasMany(Booking::class, 'status_id');
    }
}
