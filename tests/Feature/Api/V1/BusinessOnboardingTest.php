<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class BusinessOnboardingTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        // Factory default is a verified admin (email_verified_at = now()).
        $this->admin = User::factory()->create([
            'role' => UserRole::ADMIN,
        ]);
    }

    private function authenticateAs(User $user): void
    {
        $this->withHeader('Authorization', 'Bearer '.$user->createToken('test-token', ['*'])->plainTextToken);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Kinesilk Spa',
            'rut' => '11111111-1',
            'email' => 'negocio@kinesilk.cl',
            'address' => 'Av. Providencia 1234',
            'phone' => '+56223456789',
        ], $overrides);
    }

    private function associateTenant(User $user, array $attributes = []): Tenant
    {
        $tenant = Tenant::create($attributes);

        $user->tenant()->associate($tenant)->save();

        return $tenant;
    }

    // ── S12: GET /businesses → {data: null} when no tenant ──────────

    public function test_get_returns_null_when_no_tenant(): void
    {
        $this->authenticateAs($this->admin);

        $response = $this->getJson('/api/v1/businesses');

        $response->assertStatus(200);
        $response->assertJson(['data' => null]);
    }

    // ── S20-adjacent: GET /businesses → BusinessResource shape ──────

    public function test_get_returns_business_resource_when_onboarded(): void
    {
        $tenant = $this->associateTenant($this->admin, [
            'business_name' => 'Kinesilk Spa',
            'business_rut' => '11111111-1',
            'business_email' => 'negocio@kinesilk.cl',
            'business_address' => 'Av. Providencia 1234',
            'business_phone' => '+56223456789',
            'business_plan' => 'starter',
            'business_logo_url' => 'http://localhost/storage/tenant-logos/logo.webp',
        ]);

        $this->authenticateAs($this->admin);

        $response = $this->getJson('/api/v1/businesses');

        $response->assertStatus(200);
        $response->assertJson([
            'data' => [
                'id' => $tenant->id,
                'name' => 'Kinesilk Spa',
                'rut' => '11111111-1',
                'email' => 'negocio@kinesilk.cl',
                'address' => 'Av. Providencia 1234',
                'phone' => '+56223456789',
                'plan' => 'starter',
                'logo_url' => 'http://localhost/storage/tenant-logos/logo.webp',
                'created_at' => $tenant->created_at->toIso8601String(),
            ],
        ]);
    }

    // ── S13: POST /businesses → 201, tenant + associate + pivot ─────

    public function test_store_creates_tenant_associates_user_and_pivots_admin_general(): void
    {
        $this->authenticateAs($this->admin);

        $response = $this->postJson('/api/v1/businesses', $this->validPayload());

        $response->assertStatus(201);
        $response->assertJsonPath('data.name', 'Kinesilk Spa');
        $response->assertJsonPath('data.rut', '11111111-1');
        $response->assertJsonPath('data.email', 'negocio@kinesilk.cl');
        $response->assertJsonPath('data.address', 'Av. Providencia 1234');
        $response->assertJsonPath('data.phone', '+56223456789');
        $response->assertJsonPath('data.plan', 'starter');
        $response->assertJsonPath('data.logo_url', null);
        $response->assertJsonPath('message', 'Tu negocio fue creado correctamente.');

        $this->assertDatabaseHas('tenants', [
            'business_name' => 'Kinesilk Spa',
            'business_rut' => '11111111-1',
            'business_email' => 'negocio@kinesilk.cl',
            'business_address' => 'Av. Providencia 1234',
            'business_phone' => '+56223456789',
            'business_plan' => 'starter',
        ]);

        $user = $this->admin->fresh();
        $this->assertNotNull($user->tenant_id);
        $this->assertDatabaseHas('user_role', [
            'user_id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'role_id' => Role::where('slug', 'admin_general')->value('id'),
        ]);
    }

    // ── S14: unverified user → 403, no tenant ───────────────────────

    public function test_store_rejects_unverified_user(): void
    {
        $unverified = User::factory()->create([
            'role' => UserRole::ADMIN,
            'email_verified_at' => null,
        ]);

        $this->authenticateAs($unverified);

        $response = $this->postJson('/api/v1/businesses', $this->validPayload());

        $response->assertStatus(403);
        $response->assertJson(['error' => 'email_not_verified']);
        $this->assertDatabaseCount('tenants', 0);
        $this->assertDatabaseCount('user_role', 0);
        $this->assertNull($unverified->fresh()->tenant_id);
    }

    // ── S15: duplicate rut → 422, no tenant/pivot ───────────────────

    public function test_store_rejects_duplicate_rut(): void
    {
        Tenant::factory()->create(['business_rut' => '11111111-1']);

        $this->authenticateAs($this->admin);

        $response = $this->postJson('/api/v1/businesses', $this->validPayload());

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['rut']);
        $this->assertDatabaseCount('tenants', 1);
        $this->assertDatabaseCount('user_role', 0);
        $this->assertNull($this->admin->fresh()->tenant_id);
    }

    public function test_store_rejects_invalid_rut_format(): void
    {
        $this->authenticateAs($this->admin);

        $response = $this->postJson('/api/v1/businesses', $this->validPayload([
            'rut' => 'not-a-rut',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['rut']);
        $this->assertDatabaseCount('tenants', 0);
    }

    // ── S16: second onboarding → 409 ────────────────────────────────

    public function test_store_rejects_second_onboarding(): void
    {
        $this->associateTenant($this->admin, ['business_name' => 'Ya Existo']);

        $this->authenticateAs($this->admin);

        $response = $this->postJson('/api/v1/businesses', $this->validPayload());

        $response->assertStatus(409);
        $response->assertJson(['error' => 'business_already_exists']);
        $this->assertDatabaseCount('tenants', 1);
        $this->assertDatabaseHas('tenants', ['business_name' => 'Ya Existo']);
    }

    // ── S17: plan default 'starter' / invalid plan → 422 ────────────

    public function test_store_defaults_plan_to_starter(): void
    {
        $this->authenticateAs($this->admin);

        $response = $this->postJson('/api/v1/businesses', $this->validPayload());

        $response->assertStatus(201);
        $this->assertSame('starter', Tenant::where('business_rut', '11111111-1')->value('business_plan'));
        $response->assertJsonPath('data.plan', 'starter');
    }

    public function test_store_rejects_invalid_plan(): void
    {
        $this->authenticateAs($this->admin);

        $response = $this->postJson('/api/v1/businesses', $this->validPayload([
            'plan' => 'pro',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['plan']);
        $this->assertDatabaseCount('tenants', 0);
        $this->assertDatabaseCount('user_role', 0);
    }
}
