<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Service extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'duration_minutes', 'slot_interval_minutes',
        'min_duration_minutes', 'max_duration_minutes',
        'price', 'wc_product_id', 'active',
    ];

    protected $casts = [
        'active' => 'boolean',
        'price' => 'decimal:2',
        'duration_minutes' => 'integer',
        'slot_interval_minutes' => 'integer',
        'min_duration_minutes' => 'integer',
        'max_duration_minutes' => 'integer',
    ];

    // ── Relaciones ─────────────────────────────────────────────────

    public function providers()
    {
        return $this->belongsToMany(Provider::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    // ── Accessors ──────────────────────────────────────────────────

    public function getEffectiveDurationMinutesAttribute(): int
    {
        return $this->duration_minutes
            ?? (int) config('booking.default_duration_minutes', 30);
    }

    public function getEffectiveSlotIntervalAttribute(): int
    {
        return $this->slot_interval_minutes
            ?? (int) config('booking.slot_interval_minutes', 30);
    }
}
