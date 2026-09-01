<?php

namespace App\Models;

use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    protected $fillable = [
        'business_name',
        'business_rut',
        'business_logo_url',
        'business_email',
        'business_address',
        'business_phone',
        'business_plan',
    ];

    protected $attributes = [
        'business_plan' => 'starter',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Business roles assigned inside this tenant (user_role pivot).
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_role', 'tenant_id', 'role_id')
            ->withPivot('user_id')
            ->withTimestamps();
    }
}
