<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function user(): User
    {
        return User::factory()->create([
            'role' => UserRole::ADMIN,
            'email_verified_at' => now(),
            'phone' => '+56911111111',
        ]);
    }

    private function authHeader(User $user): array
    {
        return [
            'Authorization' => 'Bearer '.$user->createToken('api')->plainTextToken,
        ];
    }

    public function test_requires_authentication(): void
    {
        $this->patchJson('/api/v1/auth/me', ['phone' => '+56912345678'])
            ->assertStatus(401);
    }

    public function test_updates_phone_and_returns_user_with_business(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->user();
        $user->forceFill(['tenant_id' => $tenant->id])->save();

        $this->withHeaders($this->authHeader($user))
            ->patchJson('/api/v1/auth/me', ['phone' => '+56912345678'])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.phone', '+56912345678')
            ->assertJsonPath('user.tenant_id', $tenant->id)
            ->assertJsonStructure(['user' => ['business' => ['id', 'name']]]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'phone' => '+56912345678',
        ]);
    }

    public function test_phone_is_required(): void
    {
        $user = $this->user();

        $this->withHeaders($this->authHeader($user))
            ->patchJson('/api/v1/auth/me', ['phone' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone' => 'El teléfono es obligatorio.']);
    }

    public function test_email_and_name_stay_read_only(): void
    {
        $user = $this->user();

        $this->withHeaders($this->authHeader($user))
            ->patchJson('/api/v1/auth/me', [
                'phone' => '+56912345678',
                'name' => 'Nombre Hackeado',
                'email' => 'otro@correo.cl',
            ])
            ->assertOk()
            ->assertJsonPath('user.name', $user->name)
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonPath('user.phone', '+56912345678');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => '+56912345678',
        ]);
    }
}
