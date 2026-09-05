<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Location extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'address', 'city', 'region', 'comuna', 'codigo_postal',
        'timezone', 'active', 'opening_time', 'closing_time',
        'region_id', 'comuna_id',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function providers(): HasMany
    {
        return $this->hasMany(Provider::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function comuna(): BelongsTo
    {
        return $this->belongsTo(Comuna::class);
    }
}
