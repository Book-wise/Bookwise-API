<?php

namespace App\Jobs;

use App\Models\PasswordResetToken;
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

class PushPasswordResetEmailToCarlitox implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const EVENT_USER_RESET_PASSWORD = 'user.reset_password';

    public const CHANNEL_EMAIL = 'email';

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
     * time (a stale queued link must never push), then deliver the reset link
     * (plain token) to carlitox for email delivery.
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
            ->post($webhookUrl, $this->payload($frontendUrl, $fresh));

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
     * token or the payload (REQ-5).
     */
    public function failed(\Throwable $e): void
    {
        Log::error('PushPasswordResetEmailToCarlitox failed', [
            'user_id' => $this->user->id,
            'event' => self::EVENT_USER_RESET_PASSWORD,
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * Build the carlitox password-reset payload contract (REQ-5/D7).
     *
     * @return array{
     *     event: string,
     *     channel: string,
     *     user: array{id: int, name: string, email: string},
     *     reset: array{token: string, expires_at: string},
     *     reset_url: string,
     *     triggered_at: string
     * }
     */
    private function payload(string $frontendUrl, PasswordResetToken $fresh): array
    {
        return [
            'event' => self::EVENT_USER_RESET_PASSWORD,
            'channel' => self::CHANNEL_EMAIL,
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ],
            'reset' => [
                'token' => $this->plainToken,
                'expires_at' => $fresh->expires_at->toIso8601String(),
            ],
            'reset_url' => rtrim($frontendUrl, '/')
                .'/reset-password?token='.$this->plainToken
                .'&email='.rawurlencode($this->user->email),
            'triggered_at' => now()->toIso8601String(),
        ];
    }
}
