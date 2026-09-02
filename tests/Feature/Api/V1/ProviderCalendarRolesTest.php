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

/**
 * Covers the provider calendar attendance roles contract: the `roles[]`
 * filter on GET /providers (REQ-1..REQ-3) and the tenant-scoped nested
 * `user.roles` payload on provider index/show (REQ-4..REQ-5). Fixtures and
 * helpers mirror ProviderRolesTest (no ProviderFactory exists; users.role
 * has no DB default, so every linked User fixture sets the role explicitly).
 */
class ProviderCalendarRolesTest extends TestCase
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

    private function authenticateAs(User $user, array $abilities = ['*']): void
    {
        $this->withHeader('Authorization', 'Bearer '.$user->createToken('test-token', $abilities)->plainTextToken);
    }

    private function makeTenant(string $businessName): Tenant
    {
        return Tenant::create(['business_name' => $businessName]);
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

    private function providersUrl(array $query): string
    {
        return '/api/v1/providers?'.http_build_query($query);
    }

    // ── S-1: filter happy path — tenant-scoped OR-match, no dups ────

    public function test_roles_filter_returns_only_providers_matching_under_caller_tenant(): void
    {
        $providerA = $this->makeProvider(['first_name' => 'Ana', 'email' => 'ana@test.com']);
        $userA = $this->makeLinkedUser($providerA);
        $userA->roles()->attach($this->roleId('staff'), ['tenant_id' => $this->tenant->id]);

        $providerB = $this->makeProvider(['first_name' => 'Berta', 'email' => 'berta@test.com']);
        $userB = $this->makeLinkedUser($providerB);
        $userB->roles()->attach($this->roleId('staff_readonly'), ['tenant_id' => $this->tenant->id]);

        // Linked user holding NO role under the caller tenant.
        $providerC = $this->makeProvider(['first_name' => 'Carla', 'email' => 'carla@test.com']);
        $this->makeLinkedUser($providerC);

        // Holds `staff` only under ANOTHER tenant — must never match.
        $otherTenant = $this->makeTenant('Otra Spa');
        $providerD = $this->makeProvider(['first_name' => 'Daniel', 'email' => 'daniel@test.com']);
        $userD = $this->makeLinkedUser($providerD);
        $userD->roles()->attach($this->roleId('staff'), ['tenant_id' => $otherTenant->id]);

        $this->authenticateAs($this->admin);

        $response = $this->getJson($this->providersUrl(['roles' => ['staff', 'staff_readonly']]));

        $response->assertStatus(200);
        // Exactly A and B, each once: no duplicate provider rows (provider-level whereHas).
        $providers = $response->json();
        $this->assertCount(2, $providers);
        $emails = collect($providers)->pluck('email')->all();
        $this->assertSame(['ana@test.com', 'berta@test.com'], $emails);
        $response->assertJsonPath('0.id', $providerA->id);
        $response->assertJsonPath('1.id', $providerB->id);
    }

    // ── S-2: no match under the caller tenant → empty list ──────────

    public function test_roles_filter_with_no_match_returns_empty_list(): void
    {
        $provider = $this->makeProvider(['first_name' => 'Sofia', 'email' => 'sofia@test.com']);
        $user = $this->makeLinkedUser($provider);
        // staff_readonly only: filtering by `staff` must not match it.
        $user->roles()->attach($this->roleId('staff_readonly'), ['tenant_id' => $this->tenant->id]);

        $this->authenticateAs($this->admin);

        // Proves the provider exists and is visible without the filter.
        $unfiltered = $this->getJson('/api/v1/providers');
        $unfiltered->assertStatus(200);
        $this->assertCount(1, $unfiltered->json());

        $response = $this->getJson($this->providersUrl(['roles' => ['staff']]));

        $response->assertStatus(200);
        $this->assertSame([], $response->json());
    }

    // ── S-3: composes with active + location_id filters ─────────────

    public function test_roles_filter_composes_with_active_and_location_filters(): void
    {
        $providerA = $this->makeProvider(['first_name' => 'Ana', 'email' => 'ana@test.com']);
        $userA = $this->makeLinkedUser($providerA);
        $userA->roles()->attach($this->roleId('staff'), ['tenant_id' => $this->tenant->id]);

        // Same location + staff role, but inactive → excluded by active=1.
        $providerB = $this->makeProvider([
            'first_name' => 'Bruno', 'email' => 'bruno@test.com', 'active' => false,
        ]);
        $userB = $this->makeLinkedUser($providerB);
        $userB->roles()->attach($this->roleId('staff'), ['tenant_id' => $this->tenant->id]);

        // Active + staff role, but in a different location → excluded by location_id.
        $otherLocation = Location::create(['name' => 'Other Location', 'address' => '999 Other St']);
        $providerC = $this->makeProvider([
            'first_name' => 'Carla', 'email' => 'carla@test.com', 'location_id' => $otherLocation->id,
        ]);
        $userC = $this->makeLinkedUser($providerC);
        $userC->roles()->attach($this->roleId('staff'), ['tenant_id' => $this->tenant->id]);

        // Active + same location but NO staff role → excluded by the roles filter.
        $providerD = $this->makeProvider(['first_name' => 'Diana', 'email' => 'diana@test.com']);
        $this->makeLinkedUser($providerD);

        $this->authenticateAs($this->admin);

        $response = $this->getJson($this->providersUrl([
            'roles' => ['staff'],
            'active' => 1,
            'location_id' => $this->location->id,
        ]));

        $response->assertStatus(200);
        $providers = $response->json();
        $this->assertCount(1, $providers);
        $this->assertSame($providerA->id, $providers[0]['id']);
    }

    // ── S-4a: invalid slug → 422, message family mirrors BR17 ───────

    public function test_roles_filter_rejects_unknown_slug_with_422(): void
    {
        $this->makeProvider(['email' => 'ana@test.com']);

        $this->authenticateAs($this->admin);

        $response = $this->getJson($this->providersUrl(['roles' => ['bogus']]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['roles.0']);
        $response->assertJsonValidationErrors([
            'roles.0' => 'El rol bogus no es válido.',
        ]);
    }

    // ── S-4b: duplicate slug → 422 ──────────────────────────────────

    public function test_roles_filter_rejects_duplicate_slugs_with_422(): void
    {
        $this->makeProvider(['email' => 'ana@test.com']);

        $this->authenticateAs($this->admin);

        $response = $this->getJson($this->providersUrl(['roles' => ['staff', 'staff']]));

        $response->assertStatus(422);
    }

    // ── S-5: explicitly empty roles array behaves as absent ─────────

    public function test_empty_roles_array_behaves_like_absent_filter(): void
    {
        // Plain provider, no roles anywhere.
        $plain = $this->makeProvider(['first_name' => 'Ana', 'email' => 'ana@test.com']);

        // Provider whose user holds staff under ANOTHER tenant: a tenant-scoped
        // filter would exclude it; an absent filter must include it.
        $otherTenant = $this->makeTenant('Otra Spa');
        $foreign = $this->makeProvider(['first_name' => 'Bruno', 'email' => 'bruno@test.com']);
        $user = $this->makeLinkedUser($foreign);
        $user->roles()->attach($this->roleId('staff'), ['tenant_id' => $otherTenant->id]);

        $this->authenticateAs($this->admin);

        // An explicit empty `roles` array is unrepresentable in a query string
        // (http_build_query drops it), so it is sent as the JSON GET body: the
        // endpoint must treat present-but-empty exactly like absent (S-5).
        $response = $this->json('GET', '/api/v1/providers', ['roles' => []]);

        $response->assertStatus(200);
        $providers = $response->json();
        $this->assertCount(2, $providers);
        $emails = collect($providers)->pluck('email')->sort()->values()->all();
        $this->assertSame(['ana@test.com', 'bruno@test.com'], $emails);
        $this->assertNotNull($plain->id);
    }

    // ── S-6: unfiltered list stays global, same shape/order ─────────

    public function test_unfiltered_index_returns_global_list_unchanged(): void
    {
        $this->makeProvider(['first_name' => 'Zoe', 'email' => 'zoe@test.com']);
        $withRole = $this->makeProvider(['first_name' => 'Alma', 'email' => 'alma@test.com']);
        $user = $this->makeLinkedUser($withRole);
        $user->roles()->attach($this->roleId('staff'), ['tenant_id' => $this->tenant->id]);

        $this->authenticateAs($this->admin);

        $response = $this->getJson('/api/v1/providers');

        $response->assertStatus(200);
        $providers = $response->json();
        $this->assertCount(2, $providers);
        // Ordered by first_name, global (Alma before Zoe).
        $this->assertSame('Alma', $providers[0]['first_name']);
        $this->assertSame('Zoe', $providers[1]['first_name']);
        // Roles must never be flattened to the top level (BR-C10).
        $response->assertJsonMissingPath('0.roles');
    }

    // ── S-7: tenantless caller + roles filter → 409 onboarding_required ──

    public function test_tenantless_caller_with_roles_filter_gets_409(): void
    {
        $tenantless = User::factory()->create(['role' => UserRole::ADMIN]);
        $this->makeProvider(['email' => 'ana@test.com']);

        $this->authenticateAs($tenantless);

        $response = $this->getJson($this->providersUrl(['roles' => ['staff']]));

        $response->assertStatus(409);
        $response->assertJson(['error' => 'onboarding_required']);
        $this->assertNull($response->json('data'));
    }

    // ── S-8: tenantless caller without roles → 200 global list ──────

    public function test_tenantless_caller_without_roles_gets_global_list(): void
    {
        $tenantless = User::factory()->create(['role' => UserRole::ADMIN]);
        $this->makeProvider(['first_name' => 'Ana', 'email' => 'ana@test.com']);
        $this->makeProvider(['first_name' => 'Bruno', 'email' => 'bruno@test.com']);

        $this->authenticateAs($tenantless);

        // No roles param.
        $response = $this->getJson('/api/v1/providers');
        $response->assertStatus(200);
        $this->assertCount(2, $response->json());

        // Explicit empty array must behave the same (no 409, no tenant scoping).
        $empty = $this->json('GET', '/api/v1/providers', ['roles' => []]);
        $empty->assertStatus(200);
        $this->assertCount(2, $empty->json());
    }
}
