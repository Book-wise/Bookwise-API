<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Service extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'duration_minutes', 'price', 'wc_product_id', 'active'
    ];

    protected $casts = [
        'active'           => 'boolean',
        'price'            => 'decimal:2',
        'duration_minutes' => 'integer',
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
