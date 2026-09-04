<?php

namespace App\Jobs;

use App\Mail\VerificationEmail;
use App\Models\EmailVerificationToken;
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

class SendVerificationEmail implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const EVENT_USER_VERIFY_EMAIL = 'user.verify_email';

    /**
     * Maximum number of retry attempts before marking as failed.
     */
    public int $tries = 5;

    public function __construct(
        public User $user,
        public EmailVerificationToken $verification,
        public string $plainToken,
    ) {
        $this->onQueue('notifications');
    }

    /**
     * Execute the job — re-gate verification state at run time, then deliver
     * the verification link (plain token) via Mailgun.
     */
    public function handle(): void
    {
        if ($this->user->email_verified_at !== null || $this->verification->used_at !== null) {
            return;
        }

        $frontendUrl = config('services.frontend.url');

        if (blank($frontendUrl)) {
            throw new RuntimeException('frontend url is not configured');
        }

        $verifyUrl = rtrim($frontendUrl, '/').'/verify-email?token='.$this->plainToken;

        Mail::mailer('mailgun')
            ->to($this->user->email, $this->user->name)
            ->send(new VerificationEmail($this->user, $verifyUrl));
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
        return "verify-{$this->user->id}";
    }

    /**
     * The duration (in seconds) during which the job's uniqueness lock is held.
     */
    public function uniqueFor(): int
    {
        return 60;
    }

    /**
     * Handle a job failure — log user context only, NEVER the plain
     * verification token (BR5/R7.5).
     */
    public function failed(\Throwable $e): void
    {
        Log::error('SendVerificationEmail failed', [
            'user_id' => $this->user->id,
            'event' => self::EVENT_USER_VERIFY_EMAIL,
            'error' => $e->getMessage(),
        ]);
    }
}
