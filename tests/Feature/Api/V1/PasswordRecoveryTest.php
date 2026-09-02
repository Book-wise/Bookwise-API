<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Events\UserRequestedPasswordReset;
use App\Jobs\PushPasswordResetEmailToCarlitox;
use App\Models\PasswordResetToken;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * PR 1 — forgot side of password recovery. Reset (consumption) scenarios
 * are appended by PR 2.
 */
class PasswordRecoveryTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const FORGOT_MESSAGE = 'Si el email existe, recibirás un link para restablecer tu contraseña.';

    private function forgotUser(): User
    {
        return User::factory()->create([
            'role' => UserRole::ADMIN,
            'email_verified_at' => now(),
        ]);
    }

    // ── S-3.1: Existing user — 200, hash-only storage, +60min, job queued ──

    public function test_forgot_existing_user_stores_hash_only_and_queues_reset_push(): void
    {
        Queue::fake();
        Http::fake();
        config(['services.frontend.url' => 'https://front.test']);

        $user = $this->forgotUser();

        $response = $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email]);

        $response->assertStatus(200);
        $response->assertExactJson(['message' => self::FORGOT_MESSAGE]);

        $plainToken = null;

        Queue::assertPushed(PushPasswordResetEmailToCarlitox::class, function ($job) use (&$plainToken, $user) {
            $plainToken = $job->plainToken;

            return $job->user->is($user)
                && is_string($job->plainToken)
                && strlen($job->plainToken) === 64
                && $job->token->email === $user->email;
        });

        $this->assertNotNull($plainToken, 'queued job must carry the plain 64-char token');

        // DB stores sha256 only — never the plaintext.
        $this->assertDatabaseHas('password_reset_tokens', [
            'email' => $user->email,
            'token' => hash('sha256', $plainToken),
            'used_at' => null,
        ]);
        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => $user->email,
            'token' => $plainToken,
        ]);
        $this->assertDatabaseCount('password_reset_tokens', 1);

        // expires_at = creation + EXPIRES_IN_MINUTES.
        $row = PasswordResetToken::where('email', $user->email)->firstOrFail();
        $this->assertEqualsWithDelta(
            now()->addMinutes(PasswordResetToken::EXPIRES_IN_MINUTES)->timestamp,
            $row->expires_at->timestamp,
            5
        );
    }

    // ── S-3.2: Unknown email — byte-identical 200, zero side effects ──

    public function test_forgot_unknown_email_returns_identical_200_without_side_effects(): void
    {
        Event::fake();
        Queue::fake();

        $response = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ghost@test.com']);

        $response->assertStatus(200);
        $response->assertExactJson(['message' => self::FORGOT_MESSAGE]);

        $this->assertDatabaseCount('password_reset_tokens', 0);
        Event::assertNotDispatched(UserRequestedPasswordReset::class);
        Queue::assertNothingPushed();
    }

    // ── S-3.3: Malformed / missing email — 422 under errors.email ──

    public function test_forgot_rejects_malformed_email(): void
    {
        Event::fake();
        Queue::fake();

        $response = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'no-es-un-email']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
        $response->assertJson([
            'errors' => ['email' => ['El email no tiene un formato válido.']],
        ]);

        $this->assertDatabaseCount('password_reset_tokens', 0);
        Event::assertNotDispatched(UserRequestedPasswordReset::class);
        Queue::assertNothingPushed();
    }

    public function test_forgot_requires_email(): void
    {
        Event::fake();
        Queue::fake();

        $response = $this->postJson('/api/v1/auth/forgot-password', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
        $response->assertJson([
            'errors' => ['email' => ['El email es obligatorio.']],
        ]);

        $this->assertDatabaseCount('password_reset_tokens', 0);
        Queue::assertNothingPushed();
    }

    // ── REQ-2: Forgot resubmission overwrites the single row (last-wins) ──

    public function test_forgot_resubmission_overwrites_single_row_and_supersedes_previous_token(): void
    {
        Queue::fake();
        Http::fake();

        $user = $this->forgotUser();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertStatus(200);

        $firstPlain = null;

        Queue::assertPushed(PushPasswordResetEmailToCarlitox::class, function ($job) use (&$firstPlain) {
            $firstPlain = $job->plainToken;

            return true;
        });

        $this->assertNotNull($firstPlain, 'queued job must carry the plain 64-char token');

        // Second forgot: last-wins overwrite in place (REQ-2). The repeated
        // dispatch is deduped by the per-user unique lock (S-6.1, 60s window),
        // so exactly one job is queued — but the row MUST hold a NEW hash.
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertStatus(200);

        Queue::assertPushed(PushPasswordResetEmailToCarlitox::class, 1);

        // Exactly one row, and the previous plain token no longer matches it —
        // the first link is superseded by the overwrite.
        $this->assertDatabaseCount('password_reset_tokens', 1);
        $row = PasswordResetToken::where('email', $user->email)->firstOrFail();
        $this->assertNotSame(hash('sha256', $firstPlain), $row->token);
    }

    // ── Foundation: factory states (used by job re-gate & PR 2 reset tests) ──

    public function test_password_reset_token_factory_expired_and_used_states(): void
    {
        $expiredUser = $this->forgotUser();
        $usedUser = $this->forgotUser();

        $expired = PasswordResetToken::factory()->expired()->create(['email' => $expiredUser->email]);
        $used = PasswordResetToken::factory()->used()->create(['email' => $usedUser->email]);

        // datetime cast is real: ->isPast()/->timestamp would fail on raw strings.
        $this->assertTrue($expired->expires_at->isPast());
        $this->assertNull($expired->used_at);

        $this->assertNotNull($used->used_at);
        $this->assertEqualsWithDelta(
            now()->addMinutes(PasswordResetToken::EXPIRES_IN_MINUTES)->timestamp,
            $used->expires_at->timestamp,
            5
        );
    }
}
