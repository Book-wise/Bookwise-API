<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pivot model for the user_role table (business role assignment per tenant).
 *
 * Named RoleAssignment instead of UserRole to avoid a clash with the
 * App\Enums\UserRole technical-account-type enum (D5).
 */
class RoleAssignment extends Model
{
    protected $table = 'user_role';

    protected $fillable = [
        'user_id',
        'role_id',
        'tenant_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }
}
