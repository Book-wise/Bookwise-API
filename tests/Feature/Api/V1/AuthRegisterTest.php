<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Jobs\PushVerificationEmailToCarlitox;
use App\Models\EmailVerificationToken;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AuthRegisterTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Nuevo Negocio',
            'email' => 'negocio@test.com',
            'phone' => '+56912345678',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ], $overrides);
    }

    // ── S1: Register success ────────────────────────────────────────

    public function test_register_creates_admin_user_without_token(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/v1/auth/register', $this->validPayload());

        $response->assertStatus(201);
        $response->assertJson([
            'message' => 'Tu cuenta fue creada. Revisa tu correo para verificar tu email.',
            'user' => [
                'name' => 'Nuevo Negocio',
                'email' => 'negocio@test.com',
                'role' => 'admin',
            ],
        ]);
        $this->assertArrayNotHasKey('token', $response->json());

        $user = User::where('email', 'negocio@test.com')->firstOrFail();
        $this->assertSame(UserRole::ADMIN, $user->role);
        $this->assertNull($user->tenant_id);
        $this->assertNull($user->email_verified_at);
        $this->assertSame('+56912345678', $user->phone);
    }

    // ── S2: Duplicate email → 422, no user/token/push ───────────────

    public function test_register_duplicate_email_rejected(): void
    {
        Queue::fake();

        User::factory()->create([
            'role' => UserRole::ADMIN,
            'email' => 'negocio@test.com',
        ]);

        $response = $this->postJson('/api/v1/auth/register', $this->validPayload());

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('email_verification_tokens', 0);
        Queue::assertNothingPushed();
    }

    // ── S3: Validation — short / mismatched password ────────────────

    public function test_register_rejects_short_password(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/v1/auth/register', $this->validPayload([
            'password' => 'short',
            'password_confirmation' => 'short',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['password']);
        $this->assertDatabaseCount('users', 0);
        Queue::assertNothingPushed();
    }

    public function test_register_rejects_mismatched_password_confirmation(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/v1/auth/register', $this->validPayload([
            'password_confirmation' => 'different-password',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['password']);
        $this->assertDatabaseCount('users', 0);
        Queue::assertNothingPushed();
    }

    public function test_register_requires_phone(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/v1/auth/register', $this->validPayload([
            'phone' => null,
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['phone']);
        $this->assertDatabaseCount('users', 0);
    }

    // ── S4: Token row (hash only, +48h) + push queued with plain token ──

    public function test_register_stores_hashed_token_and_queues_verification_push(): void
    {
        Queue::fake();
        Http::fake();

        $response = $this->postJson('/api/v1/auth/register', $this->validPayload());

        $response->assertStatus(201);

        $user = User::where('email', 'negocio@test.com')->firstOrFail();

        $plainToken = null;

        Queue::assertPushed(PushVerificationEmailToCarlitox::class, function ($job) use (&$plainToken, $user) {
            $plainToken = $job->plainToken;

            return $job->user->is($user)
                && is_string($job->plainToken)
                && strlen($job->plainToken) === 64;
        });

        $this->assertNotNull($plainToken, 'queued job must carry the plain 64-char token');

        // DB stores sha256 only — never the plaintext.
        $this->assertDatabaseHas('email_verification_tokens', [
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plainToken),
            'used_at' => null,
        ]);
        $this->assertDatabaseMissing('email_verification_tokens', [
            'token_hash' => $plainToken,
        ]);

        // expires_at = creation + 48h.
        $token = EmailVerificationToken::where('token_hash', hash('sha256', $plainToken))->firstOrFail();
        $this->assertEqualsWithDelta(now()->addHours(48)->timestamp, $token->expires_at->timestamp, 5);
    }
}
