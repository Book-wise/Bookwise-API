<?php

namespace App\Models;

use App\Enums\BookingSource;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Booking extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'client_id', 'service_id', 'provider_id',
        'location_id', 'status_id', 'start_time',
        'end_time', 'custom_duration_minutes',
        'price', 'notes', 'wc_order_id',
        'created_via', 'last_modified_via',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'price' => 'decimal:2',
        'custom_duration_minutes' => 'integer',
        'created_via' => BookingSource::class,
        'last_modified_via' => BookingSource::class,
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

    public function packSession()
    {
        return $this->hasOne(PackSession::class);
    }

    // ── Scopes ─────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->whereHas('status', fn ($q) => $q->where('is_cancellation', false)
        );
    }

    /**
     * Scope to find bookings that overlap with a given time range.
     *
     * @param  Builder  $query
     * @param  Carbon  $startTime
     * @param  Carbon  $endTime
     * @param  int|null  $excludeId  Booking ID to exclude from the search
     * @return Builder
     */
    public function scopeOverlapping($query, $startTime, $endTime, $excludeId = null)
    {
        return $query->where(function ($q) use ($startTime, $endTime) {
            $q->where('start_time', '<', $endTime)
                ->where('end_time', '>', $startTime);
        })
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId));
    }

    // ── Accessors ──────────────────────────────────────────────────

    public function getEffectiveDurationMinutesAttribute(): int
    {
        return $this->custom_duration_minutes
            ?? $this->service?->duration_minutes
            ?? (int) config('booking.default_duration_minutes', 30);
    }
}
