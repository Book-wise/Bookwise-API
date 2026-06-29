<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingStatus extends Model
{
    protected $fillable = [
        'name', 'color', 'is_cancellation',
    ];

    protected $casts = [
        'is_cancellation' => 'boolean',
    ];

    public function bookings()
    {
        return $this->hasMany(Booking::class, 'status_id');
    }
}
