<?php

namespace App\Models;

use Database\Factories\PasswordResetTokenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PasswordResetToken extends Model
{
    /** @use HasFactory<PasswordResetTokenFactory> */
    use HasFactory;

    /**
     * Lifetime of a minted reset token (REQ-3/REQ-4, D4).
     */
    public const EXPIRES_IN_MINUTES = 60;

    /**
     * The table's primary key is the email: one active row per email, so a new
     * forgot for the same email overwrites the row in place (last-wins, REQ-2).
     */
    protected $table = 'password_reset_tokens';

    protected $primaryKey = 'email';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * The `token` column stores the sha256 hex of the plain token only (MD1/D2);
     * the plaintext lives solely in memory, in the queued email job, and in the
     * delivered link. `updated_at` is managed by Eloquent timestamps (D5).
     *
     * @var list<string>
     */
    protected $fillable = [
        'email',
        'token',
        'expires_at',
        'used_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }
}
