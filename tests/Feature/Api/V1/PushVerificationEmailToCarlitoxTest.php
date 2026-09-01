<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Jobs\PushVerificationEmailToCarlitox;
use App\Models\EmailVerificationToken;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PushVerificationEmailToCarlitoxTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const WEBHOOK_URL = 'https://carlitox.test/webhook';

    private const FRONTEND_URL = 'https://front.test';

    private User $user;

    private EmailVerificationToken $verification;

    private string $plainToken;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.carlitox.webhook_url' => self::WEBHOOK_URL]);
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

    private function makeJob(): PushVerificationEmailToCarlitox
    {
        return new PushVerificationEmailToCarlitox(
            user: $this->user,
            verification: $this->verification,
            plainToken: $this->plainToken,
        );
    }

    // ── R7.4: Payload contract ──────────────────────────────────────

    public function test_posts_payload_contract_to_webhook_url(): void
    {
        Http::fake(['*' => Http::response(['received' => true], 200)]);
        Http::preventStrayRequests();

        $this->makeJob()->handle();

        Http::assertSent(function ($request) {
            return $request->url() === self::WEBHOOK_URL
                && $request['event'] === PushVerificationEmailToCarlitox::EVENT_USER_VERIFY_EMAIL
                && $request['channel'] === PushVerificationEmailToCarlitox::CHANNEL_EMAIL
                && $request['user']['id'] === $this->user->id
                && $request['user']['name'] === $this->user->name
                && $request['user']['email'] === $this->user->email
                && $request['verification']['token'] === $this->plainToken
                && $request['verification']['expires_at'] === $this->verification->expires_at->toIso8601String()
                && $request['verify_url'] === self::FRONTEND_URL.'/verify-email?token='.$this->plainToken
                && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $request['triggered_at']) === 1;
        });
    }

    public function test_verify_url_is_built_from_frontend_url_without_duplicate_slashes(): void
    {
        config(['services.frontend.url' => 'https://front.test/']);
        Http::fake(['*' => Http::response(['received' => true], 200)]);
        Http::preventStrayRequests();

        $this->makeJob()->handle();

        Http::assertSent(function ($request) {
            return $request['verify_url'] === 'https://front.test/verify-email?token='.$this->plainToken;
        });
    }

    // ── R7.6: Runtime re-gate — already verified / already used ─────

    public function test_skips_push_when_user_already_verified(): void
    {
        Http::fake();
        Http::preventStrayRequests();

        // forceFill: email_verified_at is intentionally not mass-assignable.
        $this->user->forceFill(['email_verified_at' => now()])->save();

        $this->makeJob()->handle();

        Http::assertNothingSent();
    }

    public function test_skips_push_when_token_already_used(): void
    {
        Http::fake();
        Http::preventStrayRequests();

        $this->verification->update(['used_at' => now()]);

        $this->makeJob()->handle();

        Http::assertNothingSent();
    }

    // ── R7.3: Blank configuration throws loudly ─────────────────────

    public function test_blank_webhook_url_throws(): void
    {
        config(['services.carlitox.webhook_url' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('carlitox webhook_url is not configured');

        $this->makeJob()->handle();
    }

    public function test_blank_frontend_url_throws(): void
    {
        config(['services.frontend.url' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('frontend url is not configured');

        $this->makeJob()->handle();
    }

    // ── R7.5: failed() never logs the plain token ───────────────────

    public function test_failed_logs_context_without_token(): void
    {
        Log::spy();

        $this->makeJob()->failed(new RuntimeException('boom'));

        Log::shouldHaveReceived('error')
            ->once()
            ->with('PushVerificationEmailToCarlitox failed', Mockery::on(function (array $context) {
                return $context['user_id'] === $this->user->id
                    && $context['event'] === PushVerificationEmailToCarlitox::EVENT_USER_VERIFY_EMAIL
                    && $context['error'] === 'boom'
                    && ! in_array($this->plainToken, $context, true)
                    && strpos(json_encode($context), $this->plainToken) === false;
            }));
    }

    // ── Queue metadata (mirrors PushNotificationToCarlitox) ─────────

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

    // ── R7.3: Non-2xx throws (RequestException → retry path) ────────

    public function test_non_2xx_response_throws(): void
    {
        Http::fake(['*' => Http::response('Server Error', 500)]);

        $this->expectException(RequestException::class);

        $this->makeJob()->handle();
    }
}
