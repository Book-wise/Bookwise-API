<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Jobs\PushPasswordResetEmailToCarlitox;
use App\Models\PasswordResetToken;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PushPasswordResetEmailToCarlitoxTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const WEBHOOK_URL = 'https://carlitox.test/webhook';

    private const FRONTEND_URL = 'https://front.test';

    private User $user;

    private PasswordResetToken $token;

    private string $plainToken;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.carlitox.webhook_url' => self::WEBHOOK_URL]);
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

    private function makeJob(): PushPasswordResetEmailToCarlitox
    {
        return new PushPasswordResetEmailToCarlitox(
            user: $this->user,
            token: $this->token,
            plainToken: $this->plainToken,
        );
    }

    // ── S-5.1: Payload contract ─────────────────────────────────────

    public function test_posts_payload_contract_to_webhook_url(): void
    {
        Http::fake(['*' => Http::response(['received' => true], 200)]);
        Http::preventStrayRequests();

        $this->makeJob()->handle();

        Http::assertSent(function ($request) {
            return $request->url() === self::WEBHOOK_URL
                && $request['event'] === PushPasswordResetEmailToCarlitox::EVENT_USER_RESET_PASSWORD
                && $request['channel'] === PushPasswordResetEmailToCarlitox::CHANNEL_EMAIL
                && $request['user']['id'] === $this->user->id
                && $request['user']['name'] === $this->user->name
                && $request['user']['email'] === $this->user->email
                && $request['reset']['token'] === $this->plainToken
                && $request['reset']['expires_at'] === $this->token->fresh()->expires_at->toIso8601String()
                && $request['reset_url'] === self::FRONTEND_URL.'/reset-password?token='
                    .$this->plainToken.'&email='.rawurlencode($this->user->email)
                && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $request['triggered_at']) === 1;
        });
    }

    public function test_reset_url_is_built_from_frontend_url_without_duplicate_slashes(): void
    {
        config(['services.frontend.url' => 'https://front.test/']);
        Http::fake(['*' => Http::response(['received' => true], 200)]);
        Http::preventStrayRequests();

        $this->makeJob()->handle();

        Http::assertSent(function ($request) {
            return $request['reset_url'] === 'https://front.test/reset-password?token='
                .$this->plainToken.'&email='.rawurlencode($this->user->email);
        });
    }

    // ── S-5.2: Runtime re-gate on the fresh row — silent skip ────────

    public function test_skips_push_when_token_row_is_gone(): void
    {
        Http::fake();
        Http::preventStrayRequests();

        $this->token->delete();

        $this->makeJob()->handle();

        Http::assertNothingSent();
    }

    public function test_skips_push_when_token_was_superseded(): void
    {
        Http::fake();
        Http::preventStrayRequests();

        // Snapshot the job first (it carries the ORIGINAL mint), then a second
        // forgot overwrites the row via updateOrCreate (last-wins, REQ-2) —
        // separate instance, exactly like the production supersede path.
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

        Http::assertNothingSent();
    }

    public function test_skips_push_when_token_was_used(): void
    {
        Http::fake();
        Http::preventStrayRequests();

        $this->token->update(['used_at' => now()]);

        $this->makeJob()->handle();

        Http::assertNothingSent();
    }

    public function test_skips_push_when_token_expired(): void
    {
        Http::fake();
        Http::preventStrayRequests();

        $this->token->update(['expires_at' => now()->subHour()]);

        $this->makeJob()->handle();

        Http::assertNothingSent();
    }

    // ── S-5.3: Blank configuration throws loudly ────────────────────

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

    // ── S-5.4: failed() never logs the plain token ──────────────────

    public function test_failed_logs_context_without_token(): void
    {
        Log::spy();

        $this->makeJob()->failed(new RuntimeException('boom'));

        Log::shouldHaveReceived('error')
            ->once()
            ->with('PushPasswordResetEmailToCarlitox failed', Mockery::on(function (array $context) {
                return $context['user_id'] === $this->user->id
                    && $context['event'] === PushPasswordResetEmailToCarlitox::EVENT_USER_RESET_PASSWORD
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

    // ── Non-2xx throws (RequestException → retry path) ──────────────

    public function test_non_2xx_response_throws(): void
    {
        Http::fake(['*' => Http::response('Server Error', 500)]);

        $this->expectException(RequestException::class);

        $this->makeJob()->handle();
    }
}
