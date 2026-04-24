<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingStatusHistory extends Model
{
    protected $fillable = [
        'booking_id', 'status_id', 'notes'
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function status()
    {
        return $this->belongsTo(BookingStatus::class, 'status_id');
    }
}
