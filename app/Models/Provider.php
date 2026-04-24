<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Provider extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'first_name', 'last_name', 'email', 'phone', 'active'
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function locations()
    {
        return $this->belongsToMany(Location::class);
    }

    public function services()
    {
        return $this->belongsToMany(Service::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }
}
