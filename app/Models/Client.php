<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'first_name', 'last_name', 'email',
        'phone', 'gender', 'wc_customer_id', 'active'
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function customAttributes()
    {
        return $this->hasMany(ClientCustomAttribute::class);
    }
}
