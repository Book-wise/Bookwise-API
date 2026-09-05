<?php

namespace App\Jobs;

use App\Mail\PasswordResetEmail;
use App\Models\PasswordResetToken;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

class SendPasswordResetEmail implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const EVENT_USER_RESET_PASSWORD = 'user.reset_password';

    /**
     * Maximum number of retry attempts before marking as failed.
     */
    public int $tries = 5;

    public function __construct(
        public User $user,
        public PasswordResetToken $token,
        public string $plainToken,
    ) {
        $this->onQueue('notifications');
    }

    /**
     * Execute the job — re-gate the reset token against a FRESH row at run
     * time (a stale queued link must never send), then deliver the reset link
     * (plain token) via Mailgun.
     */
    public function handle(): void
    {
        $fresh = PasswordResetToken::find($this->token->email);

        if (! $fresh
            || $fresh->token !== $this->token->token
            || $fresh->used_at !== null
            || $fresh->expires_at->isPast()
        ) {
            return;
        }

        $frontendUrl = config('services.frontend.url');

        if (blank($frontendUrl)) {
            throw new RuntimeException('frontend url is not configured');
        }

        $resetUrl = rtrim($frontendUrl, '/')
            .'/reset-password?token='.$this->plainToken
            .'&email='.rawurlencode($this->user->email);

        Mail::mailer('mailgun')
            ->to($this->user->email, $this->user->name)
            ->send(new PasswordResetEmail($this->user, $resetUrl));
    }

    /**
     * Get the retry backoff times in seconds.
     *
     * @return int[]
     */
    public function backoff(): array
    {
        return [3, 10, 30, 60, 120];
    }

    /**
     * Get the unique identifier for the job, preventing duplicate sends.
     */
    public function uniqueId(): string
    {
        return "reset-{$this->user->id}";
    }

    /**
     * The duration (in seconds) during which the job's uniqueness lock is held.
     */
    public function uniqueFor(): int
    {
        return 60;
    }

    /**
     * Handle a job failure — log user context only, NEVER the plain reset
     * token (REQ-5).
     */
    public function failed(\Throwable $e): void
    {
        Log::error('SendPasswordResetEmail failed', [
            'user_id' => $this->user->id,
            'event' => self::EVENT_USER_RESET_PASSWORD,
            'error' => $e->getMessage(),
        ]);
    }
}
