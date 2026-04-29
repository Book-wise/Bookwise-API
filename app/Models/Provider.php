<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Provider extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'first_name', 'last_name', 'email', 'phone', 'active'
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

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
