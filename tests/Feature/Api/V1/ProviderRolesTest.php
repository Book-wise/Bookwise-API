<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\Location;
use App\Models\Provider;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ProviderRolesTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private Tenant $tenant;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        // Factory default is a verified admin (email_verified_at = now()).
        $this->admin = User::factory()->create(['role' => UserRole::ADMIN]);

        $this->tenant = Tenant::create(['business_name' => 'Kinesilk Spa']);

        $this->admin->tenant()->associate($this->tenant)->save();

        $this->location = Location::create([
            'name' => 'Test Location',
            'address' => '123 Test St',
        ]);
    }

    private function authenticateAs(User $user, array $abilities = ['*']): void
    {
        $this->withHeader('Authorization', 'Bearer '.$user->createToken('test-token', $abilities)->plainTextToken);
    }

    private function makeProvider(array $attributes = []): Provider
    {
        return Provider::create(array_merge([
            'first_name' => 'Test',
            'last_name' => 'Provider',
            'email' => 'provider@test.com',
            'location_id' => $this->location->id,
            'active' => true,
        ], $attributes));
    }

    private function makeLinkedUser(Provider $provider, array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => UserRole::PROVIDER,
            'email' => $provider->email,
            'provider_id' => $provider->id,
            'tenant_id' => $this->tenant->id,
        ], $attributes));
    }

    private function roleId(string $slug): int
    {
        return Role::where('slug', $slug)->value('id');
    }

    // ── Auth gates: 401 / non-admin 403 / scope 403 ───────────────

    public function test_assign_roles_requires_authentication(): void
    {
        $provider = $this->makeProvider();

        $response = $this->patchJson("/api/v1/providers/{$provider->id}/roles", [
            'roles' => ['staff'],
        ]);

        $response->assertStatus(401);
    }

    public function test_assign_roles_rejects_non_admin(): void
    {
        $provider = $this->makeProvider();
        $providerUser = User::factory()->create(['role' => UserRole::PROVIDER]);

        // Token carries the required scope, so the rejection must come from role:admin.
        $this->authenticateAs($providerUser, ['providers:write']);

        $response = $this->patchJson("/api/v1/providers/{$provider->id}/roles", [
            'roles' => ['staff'],
        ]);

        $response->assertStatus(403);
        $response->assertJson(['error' => 'forbidden']);
    }

    public function test_assign_roles_requires_providers_write_scope(): void
    {
        $provider = $this->makeProvider();

        $this->authenticateAs($this->admin, ['providers:read']);

        $response = $this->patchJson("/api/v1/providers/{$provider->id}/roles", [
            'roles' => ['staff'],
        ]);

        $response->assertStatus(403);
    }

    // ── S27: admin without tenant → 409 onboarding_required ────────

    public function test_assign_roles_without_tenant_returns_409(): void
    {
        $tenantless = User::factory()->create(['role' => UserRole::ADMIN]);
        $provider = $this->makeProvider();

        $this->authenticateAs($tenantless);

        $response = $this->patchJson("/api/v1/providers/{$provider->id}/roles", [
            'roles' => ['staff'],
        ]);

        $response->assertStatus(409);
        $response->assertJson(['error' => 'onboarding_required']);
        $this->assertDatabaseCount('user_role', 0);
    }

    // ── S28: unknown provider → 404 ────────────────────────────────

    public function test_assign_roles_unknown_provider_returns_404(): void
    {
        $this->authenticateAs($this->admin);

        $response = $this->patchJson('/api/v1/providers/9999/roles', [
            'roles' => ['staff'],
        ]);

        $response->assertStatus(404);
        $response->assertJson(['error' => 'provider_not_found']);
    }

    // ── S22: replace semantics within the admin's tenant ───────────

    public function test_assign_roles_replaces_existing_roles_under_admin_tenant(): void
    {
        $provider = $this->makeProvider();
        $user = $this->makeLinkedUser($provider);
        $user->roles()->attach($this->roleId('staff'), ['tenant_id' => $this->tenant->id]);

        $this->authenticateAs($this->admin);

        $response = $this->patchJson("/api/v1/providers/{$provider->id}/roles", [
            'roles' => ['admin_local', 'recepcionista'],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.provider_id', $provider->id);
        $response->assertJsonPath('data.user_id', $user->id);

        $slugs = collect($response->json('data.roles'))->pluck('slug')->sort()->values()->all();
        $this->assertSame(['admin_local', 'recepcionista'], $slugs);

        // Exactly the new set under the admin's tenant; staff removed.
        $this->assertDatabaseCount('user_role', 2);
        $this->assertDatabaseHas('user_role', [
            'user_id' => $user->id,
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->roleId('admin_local'),
        ]);
        $this->assertDatabaseHas('user_role', [
            'user_id' => $user->id,
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->roleId('recepcionista'),
        ]);
        $this->assertDatabaseMissing('user_role', [
            'user_id' => $user->id,
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->roleId('staff'),
        ]);
    }

    // ── S23: empty array clears all roles ──────────────────────────

    public function test_assign_roles_with_empty_array_clears_all_roles(): void
    {
        $provider = $this->makeProvider();
        $user = $this->makeLinkedUser($provider);
        $user->roles()->attach($this->roleId('staff'), ['tenant_id' => $this->tenant->id]);
        $user->roles()->attach($this->roleId('recepcionista'), ['tenant_id' => $this->tenant->id]);

        $this->authenticateAs($this->admin);

        $response = $this->patchJson("/api/v1/providers/{$provider->id}/roles", [
            'roles' => [],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.user_id', $user->id);
        $this->assertSame([], $response->json('data.roles'));
        $this->assertDatabaseCount('user_role', 0);
    }

    // ── S24: auto-creates a User when the provider has none ────────

    public function test_assign_roles_auto_creates_user_when_provider_has_none(): void
    {
        $provider = $this->makeProvider();
        $this->assertNull($provider->user);

        $this->authenticateAs($this->admin);

        $response = $this->patchJson("/api/v1/providers/{$provider->id}/roles", [
            'roles' => ['staff'],
        ]);

        $response->assertStatus(200);

        $user = User::where('provider_id', $provider->id)->first();
        $this->assertNotNull($user);
        $this->assertSame(UserRole::PROVIDER, $user->role);
        $this->assertSame($provider->email, $user->email);
        $this->assertSame($provider->id, $user->provider_id);
        $this->assertSame($this->tenant->id, $user->tenant_id);
        $this->assertNotNull($user->email_verified_at);

        $response->assertJsonPath('data.user_id', $user->id);

        $this->assertDatabaseHas('user_role', [
            'user_id' => $user->id,
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->roleId('staff'),
        ]);
    }

    // ── S25: email collision → 409, never steal/merge ──────────────

    public function test_assign_roles_email_collision_returns_409_without_stealing_account(): void
    {
        $provider = $this->makeProvider(['email' => 'collision@test.com']);
        User::factory()->create(['email' => 'collision@test.com', 'role' => UserRole::CLIENT]);

        $this->authenticateAs($this->admin);

        $response = $this->patchJson("/api/v1/providers/{$provider->id}/roles", [
            'roles' => ['staff'],
        ]);

        $response->assertStatus(409);
        $response->assertJson(['error' => 'email_collision']);

        // No user created for the provider, no pivot rows touched.
        $this->assertDatabaseMissing('users', ['provider_id' => $provider->id]);
        $this->assertDatabaseCount('user_role', 0);
    }

    // ── S26: unknown slug / duplicate slugs → 422, pivot unchanged ─

    public function test_assign_roles_rejects_unknown_slug(): void
    {
        $provider = $this->makeProvider();
        $user = $this->makeLinkedUser($provider);
        $user->roles()->attach($this->roleId('staff'), ['tenant_id' => $this->tenant->id]);

        $this->authenticateAs($this->admin);

        $response = $this->patchJson("/api/v1/providers/{$provider->id}/roles", [
            'roles' => ['bogus'],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['roles.0']);
        $response->assertJsonValidationErrors([
            'roles.0' => 'El rol bogus no es válido.',
        ]);
        $this->assertDatabaseHas('user_role', [
            'user_id' => $user->id,
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->roleId('staff'),
        ]);
    }

    public function test_assign_roles_requires_the_roles_field(): void
    {
        $provider = $this->makeProvider();
        $user = $this->makeLinkedUser($provider);
        $user->roles()->attach($this->roleId('staff'), ['tenant_id' => $this->tenant->id]);

        $this->authenticateAs($this->admin);

        $response = $this->patchJson("/api/v1/providers/{$provider->id}/roles", []);

        $response->assertStatus(422);
        $this->assertDatabaseHas('user_role', [
            'user_id' => $user->id,
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->roleId('staff'),
        ]);
    }

    public function test_assign_roles_rejects_duplicate_slugs(): void
    {
        $provider = $this->makeProvider();
        $user = $this->makeLinkedUser($provider);
        $user->roles()->attach($this->roleId('staff'), ['tenant_id' => $this->tenant->id]);

        $this->authenticateAs($this->admin);

        $response = $this->patchJson("/api/v1/providers/{$provider->id}/roles", [
            'roles' => ['staff', 'staff'],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('user_role', 1);
        $this->assertDatabaseHas('user_role', [
            'user_id' => $user->id,
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->roleId('staff'),
        ]);
    }
}
