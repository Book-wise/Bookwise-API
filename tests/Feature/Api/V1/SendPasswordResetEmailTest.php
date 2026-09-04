<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Jobs\SendPasswordResetEmail;
use App\Mail\PasswordResetEmail;
use App\Models\PasswordResetToken;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SendPasswordResetEmailTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const FRONTEND_URL = 'https://front.test';

    private User $user;

    private PasswordResetToken $token;

    private string $plainToken;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.frontend.url' => self::FRONTEND_URL]);

        // Deterministic email with characters that MUST be URL-encoded in the
        // reset link, so a raw concatenation regression cannot pass silently.
        $this->plainToken = Str::random(64);
        $this->user = User::factory()->create([
            'role' => UserRole::ADMIN,
            'email' => 'cliente.prueba+reset@carlitox.test',
            'email_verified_at' => now(),
        ]);
        $this->token = PasswordResetToken::create([
            'email' => $this->user->email,
            'token' => hash('sha256', $this->plainToken),
            'expires_at' => now()->addMinutes(PasswordResetToken::EXPIRES_IN_MINUTES),
            'used_at' => null,
        ]);
    }

    private function makeJob(): SendPasswordResetEmail
    {
        return new SendPasswordResetEmail(
            user: $this->user,
            token: $this->token,
            plainToken: $this->plainToken,
        );
    }

    // ── Mail delivery via Mailgun ───────────────────────────────────

    public function test_sends_password_reset_email_via_mailgun(): void
    {
        Mail::fake();

        $this->makeJob()->handle();

        Mail::assertSent(PasswordResetEmail::class, function (PasswordResetEmail $mail) {
            return $mail->hasTo($this->user->email)
                && $mail->user->is($this->user)
                && $mail->resetUrl === self::FRONTEND_URL.'/reset-password?token='
                    .$this->plainToken.'&email='.rawurlencode($this->user->email);
        });
    }

    public function test_reset_url_is_built_from_frontend_url_without_duplicate_slashes(): void
    {
        config(['services.frontend.url' => 'https://front.test/']);
        Mail::fake();

        $this->makeJob()->handle();

        Mail::assertSent(PasswordResetEmail::class, function (PasswordResetEmail $mail) {
            return $mail->resetUrl === 'https://front.test/reset-password?token='
                .$this->plainToken.'&email='.rawurlencode($this->user->email);
        });
    }

    // ── Runtime re-gate on the fresh row — silent skip ──────────────

    public function test_skips_send_when_token_row_is_gone(): void
    {
        Mail::fake();

        $this->token->delete();

        $this->makeJob()->handle();

        Mail::assertNothingSent();
    }

    public function test_skips_send_when_token_was_superseded(): void
    {
        Mail::fake();

        // Snapshot the job first (it carries the ORIGINAL mint), then a second
        // forgot overwrites the row via updateOrCreate (last-wins) — separate
        // instance, exactly like the production supersede path.
        $job = $this->makeJob();

        PasswordResetToken::updateOrCreate(
            ['email' => $this->user->email],
            [
                'token' => hash('sha256', Str::random(64)),
                'expires_at' => now()->addMinutes(PasswordResetToken::EXPIRES_IN_MINUTES),
                'used_at' => null,
            ]
        );

        $job->handle();

        Mail::assertNothingSent();
    }

    public function test_skips_send_when_token_was_used(): void
    {
        Mail::fake();

        $this->token->update(['used_at' => now()]);

        $this->makeJob()->handle();

        Mail::assertNothingSent();
    }

    public function test_skips_send_when_token_expired(): void
    {
        Mail::fake();

        $this->token->update(['expires_at' => now()->subHour()]);

        $this->makeJob()->handle();

        Mail::assertNothingSent();
    }

    // ── Blank configuration throws loudly ───────────────────────────

    public function test_blank_frontend_url_throws(): void
    {
        config(['services.frontend.url' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('frontend url is not configured');

        $this->makeJob()->handle();
    }

    // ── failed() never logs the plain token ─────────────────────────

    public function test_failed_logs_context_without_token(): void
    {
        Log::spy();

        $this->makeJob()->failed(new RuntimeException('boom'));

        Log::shouldHaveReceived('error')
            ->once()
            ->with('SendPasswordResetEmail failed', Mockery::on(function (array $context) {
                return $context['user_id'] === $this->user->id
                    && $context['event'] === SendPasswordResetEmail::EVENT_USER_RESET_PASSWORD
                    && $context['error'] === 'boom'
                    && ! in_array($this->plainToken, $context, true)
                    && strpos(json_encode($context), $this->plainToken) === false;
            }));
    }

    // ── Queue metadata ──────────────────────────────────────────────

    public function test_unique_id_and_unique_for(): void
    {
        $job = $this->makeJob();

        $this->assertSame("reset-{$this->user->id}", $job->uniqueId());
        $this->assertSame(60, $job->uniqueFor());
    }

    public function test_queue_tries_and_backoff(): void
    {
        $job = $this->makeJob();

        $this->assertSame('notifications', $job->queue);
        $this->assertSame(5, $job->tries);
        $this->assertSame([3, 10, 30, 60, 120], $job->backoff());
    }
}
