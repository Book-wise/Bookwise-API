<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Location extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'address', 'city', 'timezone', 'active', 'opening_time', 'closing_time',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function providers(): HasMany
    {
        return $this->hasMany(Provider::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }
}
