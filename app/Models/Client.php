<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'first_name', 'last_name', 'email',
        'phone', 'rut', 'gender', 'wc_customer_id',
        'address', 'notes', 'active', 'notifications_enabled',
    ];

    protected $casts = [
        'active' => 'boolean',
        'notifications_enabled' => 'boolean',
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
