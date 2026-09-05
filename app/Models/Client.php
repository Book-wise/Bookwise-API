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
        'email_new_booking', 'email_booking_confirmation', 'email_booking_cancellation',
        'whatsapp_reminder', 'whatsapp_cancellation_confirmation',
    ];

    protected $casts = [
        'active' => 'boolean',
        'notifications_enabled' => 'boolean',
        'email_new_booking' => 'boolean',
        'email_booking_confirmation' => 'boolean',
        'email_booking_cancellation' => 'boolean',
        'whatsapp_reminder' => 'boolean',
        'whatsapp_cancellation_confirmation' => 'boolean',
    ];

    protected $attributes = [
        'email_new_booking' => true,
        'email_booking_confirmation' => true,
        'email_booking_cancellation' => true,
        'whatsapp_reminder' => true,
        'whatsapp_cancellation_confirmation' => true,
    ];

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    public function customAttributes()
    {
        return $this->hasMany(ClientCustomAttribute::class);
    }
}
