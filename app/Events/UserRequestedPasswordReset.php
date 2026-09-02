<?php

namespace App\Events;

use App\Models\PasswordResetToken;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

class UserRequestedPasswordReset implements ShouldDispatchAfterCommit
{
    public function __construct(
        public User $user,
        public string $plainToken,
        public PasswordResetToken $token,
    ) {}
}
