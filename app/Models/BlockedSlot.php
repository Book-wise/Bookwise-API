<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BlockedSlot extends Model
{
    protected $fillable = [
        'location_id', 'provider_id',
        'start_time', 'end_time',
        'reason', 'repeat_group_id',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
    ];

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function provider()
    {
        return $this->belongsTo(Provider::class);
    }
}
