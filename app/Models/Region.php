<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Region extends Model
{
    protected $fillable = [
        'name', 'timezone', 'sort_order',
    ];

    public function comunas(): HasMany
    {
        return $this->hasMany(Comuna::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }
}
