<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class PasswordChangeTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function user(): User
    {
        return User::factory()->create([
            'role' => UserRole::ADMIN,
            'email_verified_at' => now(),
            'password' => 'password',
        ]);
    }

    private function authHeader(User $user): array
    {
        return [
            'Authorization' => 'Bearer '.$user->createToken('api')->plainTextToken,
        ];
    }

    // ── Requiere autenticación ────────────────────────────────────────

    public function test_requires_authentication(): void
    {
        $this->postJson('/api/v1/auth/password', [
            'current_password' => 'password',
            'password' => 'nueva-clave-123',
            'password_confirmation' => 'nueva-clave-123',
        ])->assertStatus(401);
    }

    // ── Cambio exitoso ────────────────────────────────────────────────

    public function test_changes_password_and_new_credentials_work(): void
    {
        $user = $this->user();

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/v1/auth/password', [
                'current_password' => 'password',
                'password' => 'nueva-clave-123',
                'password_confirmation' => 'nueva-clave-123',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Tu contraseña fue actualizada correctamente.');

        // El middleware auth:sanctum llama Auth::shouldUse('sanctum'); en tests la
        // app persiste entre requests y eso rompe Auth::attempt del login (guard web).
        // Restauramos el guard por defecto antes de probar el login.
        Auth::shouldUse('web');

        // La clave nueva entra y la vieja ya no.
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'nueva-clave-123',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertStatus(422);
    }

    // ── Contraseña actual incorrecta → 422 con error de campo ────────

    public function test_wrong_current_password_returns_422_field_error(): void
    {
        $user = $this->user();

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/v1/auth/password', [
                'current_password' => 'clave-equivocada',
                'password' => 'nueva-clave-123',
                'password_confirmation' => 'nueva-clave-123',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['current_password' => 'La contraseña actual no es correcta.']);
    }

    // ── La nueva no puede ser igual a la actual ──────────────────────

    public function test_new_password_must_differ_from_current(): void
    {
        $user = $this->user();

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/v1/auth/password', [
                'current_password' => 'password',
                'password' => 'password',
                'password_confirmation' => 'password',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password' => 'La nueva contraseña debe ser distinta a la actual.']);
    }

    // ── Sin oráculo: current incorrecta NO revela si password es la clave real ──
    // Regresión de seguridad: con current_password inválida la respuesta nunca
    // debe incluir "distinta a la actual", porque eso confirmaría una adivinanza.

    public function test_wrong_current_does_not_reveal_when_new_matches_current(): void
    {
        $user = $this->user();

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/v1/auth/password', [
                'current_password' => 'clave-equivocada',
                'password' => 'password', // = la clave actual real (la "adivinanza")
                'password_confirmation' => 'password',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['current_password'])
            ->assertJsonMissingValidationErrors(['password']);
    }

    // ── Reglas de validación (min 8 + confirmed) ─────────────────────

    public function test_password_requires_min_length_and_confirmation(): void
    {
        $user = $this->user();

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/v1/auth/password', [
                'current_password' => 'password',
                'password' => 'corta',
                'password_confirmation' => 'distinta',
            ])
            ->assertStatus(422)
            // 'confirmed' reporta bajo la key 'password' (no 'password_confirmation')
            ->assertJsonValidationErrors(['password']);
    }

    // ── El token actual sigue válido tras el cambio (no se fuerza logout) ──

    public function test_current_token_stays_valid_after_change(): void
    {
        $user = $this->user();

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/v1/auth/password', [
                'current_password' => 'password',
                'password' => 'nueva-clave-123',
                'password_confirmation' => 'nueva-clave-123',
            ])
            ->assertOk();

        $this->withHeaders($this->authHeader($user))
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.email', $user->email);
    }
}
