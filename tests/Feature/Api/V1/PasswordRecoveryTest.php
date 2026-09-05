<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Events\UserRequestedPasswordReset;
use App\Jobs\SendPasswordResetEmail;
use App\Models\PasswordResetToken;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PR 1 — forgot side of password recovery; PR 2 — reset (consumption)
 * scenarios S-4.1..4.8 appended below.
 */
class PasswordRecoveryTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const FORGOT_MESSAGE = 'Si el email existe, recibirás un link para restablecer tu contraseña.';

    private const RESET_MESSAGE = 'Tu contraseña fue restablecida correctamente. Ya puedes iniciar sesión.';

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
        config(['services.frontend.url' => 'https://front.test']);

        $user = $this->forgotUser();

        $response = $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email]);

        $response->assertStatus(200);
        $response->assertExactJson(['message' => self::FORGOT_MESSAGE]);

        $plainToken = null;

        Queue::assertPushed(SendPasswordResetEmail::class, function ($job) use (&$plainToken, $user) {
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

        $user = $this->forgotUser();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertStatus(200);

        $firstPlain = null;

        Queue::assertPushed(SendPasswordResetEmail::class, function ($job) use (&$firstPlain) {
            $firstPlain = $job->plainToken;

            return true;
        });

        $this->assertNotNull($firstPlain, 'queued job must carry the plain 64-char token');

        // Second forgot: last-wins overwrite in place (REQ-2). The repeated
        // dispatch is deduped by the per-user unique lock (S-6.1, 60s window),
        // so exactly one job is queued — but the row MUST hold a NEW hash.
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertStatus(200);

        Queue::assertPushed(SendPasswordResetEmail::class, 1);

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

    // ════ PR 2 — reset side (S-4.1..S-4.8) ═══════════════════════════════

    /**
     * Create the user's reset-token row (email PK) carrying the sha256 of a
     * KNOWN plaintext. Overrides let a scenario expire or pre-consume the
     * token while keeping the plain known — the factory states randomize the
     * token, so they cannot drive a reset submission (EmailVerificationTest
     * helper pattern).
     *
     * @return array{0: string, 1: PasswordResetToken}
     */
    private function makeResetToken(User $user, array $overrides = []): array
    {
        $plain = Str::random(64);

        $token = PasswordResetToken::create(array_merge([
            'email' => $user->email,
            'token' => hash('sha256', $plain),
            'expires_at' => now()->addMinutes(PasswordResetToken::EXPIRES_IN_MINUTES),
            'used_at' => null,
        ], $overrides));

        return [$plain, $token];
    }

    /**
     * @return array{email: string, token: string, password: string, password_confirmation: string}
     */
    private function resetPayload(User $user, string $plainToken, string $password = 'nueva-clave-123'): array
    {
        return [
            'email' => $user->email,
            'token' => $plainToken,
            'password' => $password,
            'password_confirmation' => $password,
        ];
    }

    // ── S-4.1: Happy path — 200, used_at set, credentials swap ──

    public function test_reset_updates_password_consumes_token_and_swaps_credentials(): void
    {
        $user = $this->forgotUser();
        [$plain] = $this->makeResetToken($user);

        $response = $this->postJson('/api/v1/auth/reset-password', $this->resetPayload($user, $plain));

        $response->assertStatus(200);
        // Exact body: fixed message ONLY — no token key, no auto-login.
        $response->assertExactJson(['message' => self::RESET_MESSAGE]);

        $this->assertNotNull(PasswordResetToken::where('email', $user->email)->firstOrFail()->used_at);

        // Reset/login are public requests, but any sanctum-authenticated call
        // earlier flips the guard; restore web before Auth::attempt (the
        // PasswordChangeTest pattern).
        Auth::shouldUse('web');

        // Old credentials fail, new ones succeed.
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertStatus(422);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'nueva-clave-123',
        ])->assertOk();
    }

    // ── S-4.2: Wrong token → 400 invalid_token, nothing consumed ──

    public function test_reset_rejects_wrong_token(): void
    {
        $user = $this->forgotUser();
        $this->makeResetToken($user);

        $response = $this->postJson('/api/v1/auth/reset-password', $this->resetPayload($user, Str::random(64)));

        $response->assertStatus(400);
        $response->assertExactJson(['error' => 'invalid_token']);

        // Token row untouched and credentials unchanged.
        $this->assertNull(PasswordResetToken::where('email', $user->email)->firstOrFail()->used_at);
        Auth::shouldUse('web');
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();
    }

    // ── S-4.3: Expired token → 400 token_expired ──

    public function test_reset_rejects_expired_token(): void
    {
        $user = $this->forgotUser();
        [$plain] = $this->makeResetToken($user, ['expires_at' => now()->subHour()]);

        $response = $this->postJson('/api/v1/auth/reset-password', $this->resetPayload($user, $plain));

        $response->assertStatus(400);
        $response->assertExactJson(['error' => 'token_expired']);
        $this->assertNull(PasswordResetToken::where('email', $user->email)->firstOrFail()->used_at);
    }

    // ── S-4.4: Already-used token → 400 token_already_used ──

    public function test_reset_rejects_already_used_token(): void
    {
        $user = $this->forgotUser();
        [$plain] = $this->makeResetToken($user, ['used_at' => now()]);

        $response = $this->postJson('/api/v1/auth/reset-password', $this->resetPayload($user, $plain));

        $response->assertStatus(400);
        $response->assertExactJson(['error' => 'token_already_used']);
    }

    // ── S-4.5: Superseded link → invalid_token; current link still works ──

    public function test_reset_rejects_superseded_token_but_current_link_succeeds(): void
    {
        $user = $this->forgotUser();
        $plainA = Str::random(64);
        $plainB = Str::random(64);

        // Two "forgot runs" for the same email: updateOrCreate overwrites the
        // single row in place (last-wins, REQ-2). The mints go through the same
        // updateOrCreate the controller uses because the per-user unique lock
        // dedupes the second job dispatch (S-6.1), hiding plain B from a job.
        PasswordResetToken::updateOrCreate(['email' => $user->email], [
            'token' => hash('sha256', $plainA),
            'expires_at' => now()->addMinutes(PasswordResetToken::EXPIRES_IN_MINUTES),
            'used_at' => null,
        ]);
        PasswordResetToken::updateOrCreate(['email' => $user->email], [
            'token' => hash('sha256', $plainB),
            'expires_at' => now()->addMinutes(PasswordResetToken::EXPIRES_IN_MINUTES),
            'used_at' => null,
        ]);

        $this->assertDatabaseCount('password_reset_tokens', 1);

        // Superseded link A is indistinguishable from a wrong token.
        $this->postJson('/api/v1/auth/reset-password', $this->resetPayload($user, $plainA))
            ->assertStatus(400)
            ->assertExactJson(['error' => 'invalid_token']);

        // The surviving (current) link B still succeeds.
        $this->postJson('/api/v1/auth/reset-password', $this->resetPayload($user, $plainB))
            ->assertStatus(200)
            ->assertExactJson(['message' => self::RESET_MESSAGE]);
    }

    // ── S-4.6: Revocation + no auto-login ──

    public function test_reset_revokes_all_sanctum_tokens_and_does_not_autologin(): void
    {
        $user = $this->forgotUser();
        [$plain] = $this->makeResetToken($user);

        $oldTokenA = $user->createToken('api')->plainTextToken;
        $oldTokenB = $user->createToken('mobile')->plainTextToken;
        $this->assertDatabaseCount('personal_access_tokens', 2);

        $response = $this->postJson('/api/v1/auth/reset-password', $this->resetPayload($user, $plain));

        $response->assertStatus(200);
        // Exact body: fixed message ONLY — proves no `token` key is returned.
        $response->assertExactJson(['message' => self::RESET_MESSAGE]);

        $this->assertDatabaseCount('personal_access_tokens', 0);

        // Revoked sessions are dead: old bearers no longer authenticate.
        $this->withHeader('Authorization', 'Bearer '.$oldTokenA)
            ->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->withHeader('Authorization', 'Bearer '.$oldTokenB)
            ->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    // ── S-4.7: Validation — 422 Spanish messages under errors.{field} ──

    public function test_reset_requires_email_token_and_password(): void
    {
        $response = $this->postJson('/api/v1/auth/reset-password', []);

        $response->assertStatus(422);
        $response->assertJson([
            'errors' => [
                'email' => ['El email es obligatorio.'],
                'token' => ['El token es obligatorio.'],
                'password' => ['La contraseña es obligatoria.'],
            ],
        ]);
    }

    public function test_reset_rejects_invalid_email_format(): void
    {
        $response = $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'no-es-un-email',
            'token' => Str::random(64),
            'password' => 'nueva-clave-123',
            'password_confirmation' => 'nueva-clave-123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email' => 'El email no tiene un formato válido.']);
    }

    public function test_reset_rejects_short_and_unconfirmed_password(): void
    {
        $response = $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'alguien@test.com',
            'token' => Str::random(64),
            'password' => 'corta',
            'password_confirmation' => 'distinta',
        ]);

        $response->assertStatus(422);
        // Both min and confirmed report under errors.password (never
        // password_confirmation) — mirror RegisterRequest conventions.
        $this->assertContains(
            'La contraseña debe tener al menos 8 caracteres.',
            $response->json('errors.password')
        );
        $this->assertContains(
            'La confirmación de contraseña no coincide.',
            $response->json('errors.password')
        );
    }

    // ── S-4.8: Sequential reuse of one token → exactly one success ──
    // lockForUpdate is a no-op on sqlite :memory:, so the race is exercised
    // sequentially (second use hits the consumed row); MySQL row locks give
    // real mutual exclusion in production (design note).

    public function test_reset_second_use_of_same_token_is_rejected_and_winner_password_stands(): void
    {
        $user = $this->forgotUser();
        [$plain] = $this->makeResetToken($user);

        $this->postJson('/api/v1/auth/reset-password', $this->resetPayload($user, $plain))
            ->assertStatus(200);

        // The consumed row now has used_at set → token_already_used.
        $this->postJson('/api/v1/auth/reset-password', $this->resetPayload($user, $plain))
            ->assertStatus(400)
            ->assertExactJson(['error' => 'token_already_used']);

        // The stored password is the winner's (the first reset's password).
        Auth::shouldUse('web');
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'nueva-clave-123',
        ])->assertOk();
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertStatus(422);
    }
}
