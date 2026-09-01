<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class UserProfileTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Factory default is a verified user (email_verified_at = now()).
        $this->user = User::factory()->create([
            'role' => UserRole::ADMIN,
        ]);
    }

    private function authenticateAs(User $user): void
    {
        $this->withHeader('Authorization', 'Bearer '.$user->createToken('test-token', ['*'])->plainTextToken);
    }

    private function associateTenant(User $user, array $attributes = []): Tenant
    {
        $tenant = Tenant::create($attributes);

        $user->tenant()->associate($tenant)->save();

        return $tenant;
    }

    // ── S20: /auth/me with tenant → onboarding_complete true + business ──

    public function test_auth_me_returns_onboarding_complete_true_with_business_when_onboarded(): void
    {
        $tenant = $this->associateTenant($this->user, [
            'business_name' => 'Kinesilk Spa',
            'business_rut' => '11111111-1',
            'business_email' => 'negocio@kinesilk.cl',
            'business_address' => 'Av. Providencia 1234',
            'business_phone' => '+56223456789',
            'business_plan' => 'starter',
            'business_logo_url' => 'http://localhost/storage/tenant-logos/logo.webp',
        ]);

        $this->authenticateAs($this->user);

        $response = $this->getJson('/api/v1/auth/me');

        $response->assertStatus(200);
        $response->assertJsonPath('user.id', $this->user->id);
        $response->assertJsonPath('user.name', $this->user->name);
        $response->assertJsonPath('user.email', $this->user->email);
        $response->assertJsonPath('user.phone', $this->user->phone);
        $response->assertJsonPath('user.role', 'admin');
        $response->assertJsonPath('user.provider_id', null);
        $response->assertJsonPath('user.tenant_id', $tenant->id);
        $response->assertJsonPath('user.email_verified_at', $this->user->email_verified_at->toIso8601String());
        $response->assertJsonPath('user.onboarding_complete', true);
        $response->assertJsonPath('user.business.id', $tenant->id);
        $response->assertJsonPath('user.business.name', 'Kinesilk Spa');
        $response->assertJsonPath('user.business.rut', '11111111-1');
        $response->assertJsonPath('user.business.email', 'negocio@kinesilk.cl');
        $response->assertJsonPath('user.business.address', 'Av. Providencia 1234');
        $response->assertJsonPath('user.business.phone', '+56223456789');
        $response->assertJsonPath('user.business.plan', 'starter');
        $response->assertJsonPath('user.business.logo_url', 'http://localhost/storage/tenant-logos/logo.webp');
    }

    // ── S20: /auth/me without tenant → onboarding_complete false + business null ──

    public function test_auth_me_returns_onboarding_complete_false_with_null_business_without_tenant(): void
    {
        $this->authenticateAs($this->user);

        $response = $this->getJson('/api/v1/auth/me');

        $response->assertStatus(200);
        $response->assertJsonPath('user.tenant_id', null);
        $response->assertJsonPath('user.onboarding_complete', false);
        $response->assertJsonPath('user.business', null);
    }

    // ── 401 unauthenticated ──────────────────────────────────────────────

    public function test_auth_me_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertStatus(401);
    }
}
