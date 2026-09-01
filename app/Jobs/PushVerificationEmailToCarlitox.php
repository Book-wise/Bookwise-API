<?php

namespace App\Jobs;

use App\Models\EmailVerificationToken;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PushVerificationEmailToCarlitox implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const EVENT_USER_VERIFY_EMAIL = 'user.verify_email';

    public const CHANNEL_EMAIL = 'email';

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
     * Execute the job — re-gate verification state at run time, then push the
     * verification link (plain token) to carlitox for email delivery.
     */
    public function handle(): void
    {
        if ($this->user->email_verified_at !== null || $this->verification->used_at !== null) {
            return;
        }

        $webhookUrl = config('services.carlitox.webhook_url');

        if (blank($webhookUrl)) {
            throw new RuntimeException('carlitox webhook_url is not configured');
        }

        $frontendUrl = config('services.frontend.url');

        if (blank($frontendUrl)) {
            throw new RuntimeException('frontend url is not configured');
        }

        $response = Http::timeout(10)
            ->connectTimeout(5)
            ->post($webhookUrl, $this->payload($frontendUrl));

        $response->throw();
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
     * Get the unique identifier for the job, preventing duplicate pushes.
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
     * Handle a job failure — log user context only, NEVER the verification
     * token or the payload (BR5/R7.5).
     */
    public function failed(\Throwable $e): void
    {
        Log::error('PushVerificationEmailToCarlitox failed', [
            'user_id' => $this->user->id,
            'event' => self::EVENT_USER_VERIFY_EMAIL,
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * Build the carlitox verification payload contract (R7.4).
     *
     * @return array{
     *     event: string,
     *     channel: string,
     *     user: array{id: int, name: string, email: string},
     *     verification: array{token: string, expires_at: string},
     *     verify_url: string,
     *     triggered_at: string
     * }
     */
    private function payload(string $frontendUrl): array
    {
        return [
            'event' => self::EVENT_USER_VERIFY_EMAIL,
            'channel' => self::CHANNEL_EMAIL,
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ],
            'verification' => [
                'token' => $this->plainToken,
                'expires_at' => $this->verification->expires_at->toIso8601String(),
            ],
            'verify_url' => rtrim($frontendUrl, '/').'/verify-email?token='.$this->plainToken,
            'triggered_at' => now()->toIso8601String(),
        ];
    }
}
