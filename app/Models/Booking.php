<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Booking extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'client_id', 'service_id', 'provider_id',
        'location_id', 'status_id', 'start_time',
        'end_time', 'custom_duration_minutes',
        'price', 'notes', 'wc_order_id'
    ];

    protected $casts = [
        'start_time'              => 'datetime',
        'end_time'                => 'datetime',
        'price'                   => 'decimal:2',
        'custom_duration_minutes' => 'integer',
    ];

    // ── Relaciones ─────────────────────────────────────────────────

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function provider()
    {
        return $this->belongsTo(Provider::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function status()
    {
        return $this->belongsTo(BookingStatus::class, 'status_id');
    }

    public function statusHistory()
    {
        return $this->hasMany(BookingStatusHistory::class);
    }

    public function sale()
    {
        return $this->hasOne(Sale::class);
    }

    // ── Scopes ─────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->whereHas('status', fn($q) =>
            $q->where('is_cancellation', false)
        );
    }

    // ── Accessors ──────────────────────────────────────────────────

    public function getEffectiveDurationMinutesAttribute(): int
    {
        return $this->custom_duration_minutes
            ?? $this->service?->duration_minutes
            ?? (int) env('BOOKING_DEFAULT_DURATION_MINUTES', 30);
    }
}
