<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\Location;
use App\Models\Provider;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ProviderStaffBackfillService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * Covers the two provider contract/default-role rules:
 *  1. GET /providers exposes top-level `roles` (`{ id, name, slug }`) while the
 *     nested `user.roles` (`{ slug, name }`) stays present.
 *  2. POST /providers gives a new provider the default `staff` role (editable
 *     later), scoped to the admin tenant, skipping when the admin has no
 *     tenant or when the provider email is already owned by another account.
 *  3. The idempotent ProviderStaffBackfillService backfill.
 * Mirrors ProviderRolesTest/ProviderCalendarRolesTest fixtures (no
 * ProviderFactory exists).
 */
class ProviderRoleDefaultTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private Tenant $tenant;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->admin = User::factory()->create(['role' => UserRole::ADMIN]);

        $this->tenant = Tenant::create(['business_name' => 'Kinesilk Spa']);

        $this->admin->tenant()->associate($this->tenant)->save();

        $this->location = Location::create([
            'name' => 'Test Location',
            'address' => '123 Test St',
        ]);
    }

    private function authenticateAs(User $user): void
    {
        $this->withHeader('Authorization', 'Bearer '.$user->createToken('test-token', ['*'])->plainTextToken);
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

    private function storePayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Nuevo',
            'last_name' => 'Profesional',
            'email' => 'nuevo@test.com',
            'location_id' => $this->location->id,
            'active' => true,
        ], $overrides);
    }

    // ── Contract: top-level roles + nested user.roles on index/show ──

    public function test_index_exposes_top_level_roles_and_nested_user_roles(): void
    {
        $provider = $this->makeProvider(['email' => 'ana@test.com']);
        $user = $this->makeLinkedUser($provider);
        $user->roles()->attach($this->roleId('staff'), ['tenant_id' => $this->tenant->id]);

        $this->authenticateAs($this->admin);

        $response = $this->getJson('/api/v1/providers');

        $response->assertStatus(200);
        $providers = $response->json();
        $this->assertCount(1, $providers);

        // Top-level `roles` uses the frontend Role shape: { id, name, slug }.
        $response->assertJsonPath('0.roles', [
            ['id' => $this->roleId('staff'), 'slug' => 'staff', 'name' => 'Staff'],
        ]);
        // The nested `user.roles` must NOT regress (still { slug, name }).
        $response->assertJsonPath('0.user.roles', [
            ['slug' => 'staff', 'name' => 'Staff'],
        ]);
        $this->assertSame($user->id, $providers[0]['user']['id']);
    }

    public function test_show_exposes_top_level_roles_and_nested_user_roles(): void
    {
        $provider = $this->makeProvider(['email' => 'beta@test.com']);
        $user = $this->makeLinkedUser($provider);
        $user->roles()->attach($this->roleId('staff'), ['tenant_id' => $this->tenant->id]);

        $this->authenticateAs($this->admin);

        $response = $this->getJson("/api/v1/providers/{$provider->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.roles', [
            ['id' => $this->roleId('staff'), 'slug' => 'staff', 'name' => 'Staff'],
        ]);
        $response->assertJsonPath('data.user.roles', [
            ['slug' => 'staff', 'name' => 'Staff'],
        ]);
    }

    // ── Creation default: staff scoped to the admin tenant ──────────

    public function test_store_assigns_default_staff_role_to_new_provider(): void
    {
        $this->authenticateAs($this->admin);

        $response = $this->postJson('/api/v1/providers', $this->storePayload());

        $response->assertStatus(201);
        // The create response stays byte-identical to the pre-change shape: the
        // linked user + roles are assigned in the DB but not leaked here (the
        // client reads them via index/show).
        $response->assertJsonMissingPath('data.user');
        $response->assertJsonMissingPath('data.roles');

        $provider = Provider::where('email', 'nuevo@test.com')->firstOrFail();
        $user = User::where('provider_id', $provider->id)->first();

        $this->assertNotNull($user);
        $this->assertSame(UserRole::PROVIDER, $user->role);
        $this->assertSame($this->tenant->id, $user->tenant_id);
        $this->assertNotNull($user->email_verified_at);

        $this->assertDatabaseHas('user_role', [
            'user_id' => $user->id,
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->roleId('staff'),
        ]);
    }

    // ── No tenant: provider created but no default role ─────────────

    public function test_store_without_tenant_creates_provider_without_default_role(): void
    {
        $tenantless = User::factory()->create(['role' => UserRole::ADMIN]);
        $this->authenticateAs($tenantless);

        $response = $this->postJson('/api/v1/providers', $this->storePayload());

        $response->assertStatus(201);

        $provider = Provider::where('email', 'nuevo@test.com')->firstOrFail();
        // No linked user auto-created and no role assigned.
        $this->assertDatabaseMissing('users', ['provider_id' => $provider->id]);
        $this->assertDatabaseCount('user_role', 0);
    }

    // ── Email collision: provider created, no user/pivot, no 409 ────

    public function test_store_email_collision_creates_provider_without_user_or_role(): void
    {
        // Another account already owns the provider email (never steal/merge).
        User::factory()->create([
            'email' => 'collision@test.com',
            'role' => UserRole::CLIENT,
        ]);

        $this->authenticateAs($this->admin);

        $response = $this->postJson('/api/v1/providers', $this->storePayload([
            'email' => 'collision@test.com',
        ]));

        // Provider creation must NOT be rejected (no 409, no 422).
        $response->assertStatus(201);

        $provider = Provider::where('email', 'collision@test.com')->firstOrFail();
        // No provider-linked user, no pivot rows touched.
        $this->assertDatabaseMissing('users', ['provider_id' => $provider->id]);
        $this->assertDatabaseCount('user_role', 0);
    }

    // ── Backfill: roleless gets staff once; already-role is untouched ──

    public function test_backfill_assigns_staff_to_roleless_provider_once(): void
    {
        $roleless = $this->makeProvider(['email' => 'roleless@test.com']);
        $user = $this->makeLinkedUser($roleless);

        $assigned = app(ProviderStaffBackfillService::class)->backfill();

        $this->assertSame(1, $assigned);
        $this->assertDatabaseHas('user_role', [
            'user_id' => $user->id,
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->roleId('staff'),
        ]);

        // Idempotent re-run assigns nothing further.
        $this->assertSame(0, app(ProviderStaffBackfillService::class)->backfill());
        $this->assertDatabaseCount('user_role', 1);
    }

    public function test_backfill_leaves_provider_with_existing_roles_untouched(): void
    {
        $withRole = $this->makeProvider(['email' => 'already@test.com']);
        $user = $this->makeLinkedUser($withRole);
        $user->roles()->attach($this->roleId('admin_local'), ['tenant_id' => $this->tenant->id]);

        $assigned = app(ProviderStaffBackfillService::class)->backfill();

        $this->assertSame(0, $assigned);
        // Still just admin_local — no staff added, existing role preserved.
        $this->assertDatabaseCount('user_role', 1);
        $this->assertDatabaseMissing('user_role', [
            'user_id' => $user->id,
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->roleId('staff'),
        ]);
        $this->assertDatabaseHas('user_role', [
            'user_id' => $user->id,
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->roleId('admin_local'),
        ]);
    }

    public function test_backfill_skips_provider_without_linked_user(): void
    {
        $userless = $this->makeProvider(['email' => 'no-user@test.com']);
        $this->assertNull($userless->user);

        $assigned = app(ProviderStaffBackfillService::class)->backfill();

        $this->assertSame(0, $assigned);
        $this->assertDatabaseCount('user_role', 0);
        // No user is manufactured for the user-less provider.
        $this->assertDatabaseMissing('users', ['provider_id' => $userless->id]);
    }
}
