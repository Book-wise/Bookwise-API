<?php

namespace App\Events;

use App\Models\EmailVerificationToken;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

class UserRegistered implements ShouldDispatchAfterCommit
{
    public function __construct(
        public User $user,
        public string $plainToken,
        public EmailVerificationToken $verification,
    ) {}
}
