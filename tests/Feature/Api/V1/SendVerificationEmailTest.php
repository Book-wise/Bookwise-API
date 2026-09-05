<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Jobs\SendVerificationEmail;
use App\Mail\VerificationEmail;
use App\Models\EmailVerificationToken;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SendVerificationEmailTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const FRONTEND_URL = 'https://front.test';

    private User $user;

    private EmailVerificationToken $verification;

    private string $plainToken;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.frontend.url' => self::FRONTEND_URL]);

        $this->plainToken = Str::random(64);
        $this->user = User::factory()->create([
            'role' => UserRole::ADMIN,
            'email_verified_at' => null,
        ]);
        $this->verification = EmailVerificationToken::create([
            'user_id' => $this->user->id,
            'token_hash' => hash('sha256', $this->plainToken),
            'expires_at' => now()->addHours(48),
        ]);
    }

    private function makeJob(): SendVerificationEmail
    {
        return new SendVerificationEmail(
            user: $this->user,
            verification: $this->verification,
            plainToken: $this->plainToken,
        );
    }

    // ── Mail delivery via Mailgun ───────────────────────────────────

    public function test_sends_verification_email_via_mailgun(): void
    {
        Mail::fake();

        $this->makeJob()->handle();

        Mail::assertSent(VerificationEmail::class, function (VerificationEmail $mail) {
            return $mail->hasTo($this->user->email)
                && $mail->user->is($this->user)
                && $mail->verifyUrl === self::FRONTEND_URL.'/verify-email?token='.$this->plainToken;
        });
    }

    public function test_verify_url_is_built_from_frontend_url_without_duplicate_slashes(): void
    {
        config(['services.frontend.url' => 'https://front.test/']);
        Mail::fake();

        $this->makeJob()->handle();

        Mail::assertSent(VerificationEmail::class, function (VerificationEmail $mail) {
            return $mail->verifyUrl === 'https://front.test/verify-email?token='.$this->plainToken;
        });
    }

    // ── Runtime re-gate — already verified / already used ───────────

    public function test_skips_send_when_user_already_verified(): void
    {
        Mail::fake();

        // forceFill: email_verified_at is intentionally not mass-assignable.
        $this->user->forceFill(['email_verified_at' => now()])->save();

        $this->makeJob()->handle();

        Mail::assertNothingSent();
    }

    public function test_skips_send_when_token_already_used(): void
    {
        Mail::fake();

        $this->verification->update(['used_at' => now()]);

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
            ->with('SendVerificationEmail failed', Mockery::on(function (array $context) {
                return $context['user_id'] === $this->user->id
                    && $context['event'] === SendVerificationEmail::EVENT_USER_VERIFY_EMAIL
                    && $context['error'] === 'boom'
                    && ! in_array($this->plainToken, $context, true)
                    && strpos(json_encode($context), $this->plainToken) === false;
            }));
    }

    // ── Queue metadata ──────────────────────────────────────────────

    public function test_unique_id_and_unique_for(): void
    {
        $job = $this->makeJob();

        $this->assertSame("verify-{$this->user->id}", $job->uniqueId());
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
