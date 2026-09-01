<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\EmailVerificationToken;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * Create a user and an unused, unexpired verification token with a known
     * plaintext value. Returns [plainToken, token].
     *
     * @return array{0: string, 1: EmailVerificationToken}
     */
    private function makeVerificationToken(User $user, array $overrides = []): array
    {
        $plain = Str::random(64);

        $token = EmailVerificationToken::create(array_merge([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addHours(48),
        ], $overrides));

        return [$plain, $token];
    }

    private function unverifiedUser(): User
    {
        return User::factory()->create([
            'role' => UserRole::ADMIN,
            'email_verified_at' => null,
        ]);
    }

    // ── S5: Verify success ──────────────────────────────────────────

    public function test_verify_email_sets_verified_at_and_marks_token_used(): void
    {
        $user = $this->unverifiedUser();
        [$plain, $token] = $this->makeVerificationToken($user);

        $response = $this->patchJson('/api/v1/auth/verify-email', ['token' => $plain]);

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Tu email fue verificado correctamente.',
            'user' => ['email' => $user->email],
        ]);

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertNotNull($token->fresh()->used_at);
    }

    // ── R6.4: Already-verified user + valid unused token → idempotent 200 ──

    public function test_verify_email_for_already_verified_user_consumes_token_and_returns_200(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::ADMIN,
        ]);
        [$plain, $token] = $this->makeVerificationToken($user);

        $response = $this->patchJson('/api/v1/auth/verify-email', ['token' => $plain]);

        $response->assertStatus(200);
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertNotNull($token->fresh()->used_at);
    }

    // ── S6: Invalid token ───────────────────────────────────────────

    public function test_verify_email_rejects_unknown_token(): void
    {
        $user = $this->unverifiedUser();

        $response = $this->patchJson('/api/v1/auth/verify-email', ['token' => Str::random(64)]);

        $response->assertStatus(400);
        $response->assertJson(['error' => 'invalid_token']);
        $this->assertNull($user->fresh()->email_verified_at);
    }

    // ── S7: Expired token ───────────────────────────────────────────

    public function test_verify_email_rejects_expired_token(): void
    {
        $user = $this->unverifiedUser();
        [$plain] = $this->makeVerificationToken($user, [
            'expires_at' => now()->subHour(),
        ]);

        $response = $this->patchJson('/api/v1/auth/verify-email', ['token' => $plain]);

        $response->assertStatus(400);
        $response->assertJson(['error' => 'token_expired']);
        $this->assertNull($user->fresh()->email_verified_at);
    }

    // ── S8: Already-used token ──────────────────────────────────────

    public function test_verify_email_rejects_already_used_token(): void
    {
        $user = $this->unverifiedUser();
        [$plain] = $this->makeVerificationToken($user, [
            'used_at' => now(),
        ]);

        $response = $this->patchJson('/api/v1/auth/verify-email', ['token' => $plain]);

        $response->assertStatus(400);
        $response->assertJson(['error' => 'token_already_used']);
        $this->assertNull($user->fresh()->email_verified_at);
    }

    // ── S9: Login gate — unverified → 403, no token row ─────────────

    public function test_login_rejects_unverified_user_without_creating_token(): void
    {
        $user = $this->unverifiedUser();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertStatus(403);
        $response->assertJson(['error' => 'email_not_verified']);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    // ── S10: Login gate — verified user logs in normally ────────────

    public function test_login_verified_user_returns_token(): void
    {
        $user = $this->unverifiedUser();
        [$plain] = $this->makeVerificationToken($user);

        $this->patchJson('/api/v1/auth/verify-email', ['token' => $plain])->assertStatus(200);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'user' => ['email' => $user->email],
        ]);
        $this->assertNotNull($response->json('token'));
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    // ── S11: Backfilled/legacy users pass the gate ──────────────────

    public function test_login_backfilled_legacy_user_succeeds(): void
    {
        // Simulates R1.2 backfill: an existing user whose email_verified_at
        // was set to now() by the migration, never going through register.
        $legacy = User::factory()->create([
            'role' => UserRole::ADMIN,
            'email_verified_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $legacy->email,
            'password' => 'password',
        ]);

        $response->assertStatus(200);
        $this->assertNotNull($response->json('token'));
    }

    // ── S9b: Wrong credentials path unchanged (422) ─────────────────

    public function test_login_wrong_credentials_still_returns_422(): void
    {
        $user = $this->unverifiedUser();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
