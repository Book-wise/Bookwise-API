<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Location extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'address', 'city', 'timezone', 'active'
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function providers()
    {
        return $this->belongsToMany(Provider::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }
}
