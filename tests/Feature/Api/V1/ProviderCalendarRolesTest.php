<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\BookingStatus;
use App\Models\Client;
use App\Models\Location;
use App\Models\Provider;
use App\Models\Role;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\ProviderCalendarRoleSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Covers the provider calendar attendance roles contract: the `roles[]`
 * filter on GET /providers (REQ-1..REQ-3), the tenant-scoped nested
 * `user.roles` payload on provider index/show (REQ-4..REQ-5), and the
 * email-keyed ProviderCalendarRoleSeeder tenant-1 attendance roles
 * (REQ-6 / S-13..S-15). Fixtures and helpers mirror ProviderRolesTest
 * (no ProviderFactory exists; users.role has no DB default, so every
 * linked User fixture sets the role explicitly).
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

    // ── S-9: staff + staff_readonly union; staff alone excludes readonly ──

    public function test_attendance_set_is_union_of_staff_and_staff_readonly(): void
    {
        $staff = $this->makeProvider(['first_name' => 'Ana', 'email' => 'ana@test.com']);
        $staffUser = $this->makeLinkedUser($staff);
        $staffUser->roles()->attach($this->roleId('staff'), ['tenant_id' => $this->tenant->id]);

        $readonly = $this->makeProvider(['first_name' => 'Bruno', 'email' => 'bruno@test.com']);
        $readonlyUser = $this->makeLinkedUser($readonly);
        $readonlyUser->roles()->attach($this->roleId('staff_readonly'), ['tenant_id' => $this->tenant->id]);

        $this->authenticateAs($this->admin);

        // Attendance = union of the set: both are returned.
        $union = $this->getJson($this->providersUrl(['roles' => ['staff', 'staff_readonly']]));
        $union->assertStatus(200);
        $this->assertCount(2, $union->json());

        // Filtering staff alone excludes the staff_readonly-only provider.
        $staffOnly = $this->getJson($this->providersUrl(['roles' => ['staff']]));
        $staffOnly->assertStatus(200);
        $providers = $staffOnly->json();
        $this->assertCount(1, $providers);
        $this->assertSame($staff->id, $providers[0]['id']);
    }

    // ── S-10: index exposes tenant-scoped nested user.roles ──────────

    public function test_index_exposes_tenant_scoped_nested_user_roles(): void
    {
        $otherTenant = $this->makeTenant('Otra Spa');

        // Role-less linked user → user.roles must be [].
        $roleless = $this->makeProvider(['first_name' => 'Alpha', 'email' => 'alpha@test.com']);
        $rolelessUser = $this->makeLinkedUser($roleless);

        // Holds staff under the caller tenant AND admin_local under another
        // tenant: only the caller-tenant role may surface.
        $hybrid = $this->makeProvider(['first_name' => 'Beta', 'email' => 'beta@test.com']);
        $hybridUser = $this->makeLinkedUser($hybrid);
        $hybridUser->roles()->attach($this->roleId('staff'), ['tenant_id' => $this->tenant->id]);
        $hybridUser->roles()->attach($this->roleId('admin_local'), ['tenant_id' => $otherTenant->id]);

        $this->authenticateAs($this->admin);

        $response = $this->getJson('/api/v1/providers');

        $response->assertStatus(200);
        $providers = $response->json();
        $this->assertCount(2, $providers);

        // Alpha: role-less user → roles: [].
        $this->assertSame($roleless->id, $providers[0]['id']);
        $response->assertJsonPath('0.user.id', $rolelessUser->id);
        $this->assertSame([], $providers[0]['user']['roles']);

        // Beta: nested user object with only the caller-tenant role.
        $this->assertSame($hybrid->id, $providers[1]['id']);
        $response->assertJsonPath('1.user.id', $hybridUser->id);
        $response->assertJsonPath('1.user.name', $hybridUser->name);
        $response->assertJsonPath('1.user.email', $hybridUser->email);
        $response->assertJsonPath('1.user.roles', [
            ['slug' => 'staff', 'name' => 'Staff'],
        ]);
    }

    // ── S-10: show exposes the same tenant-scoped nested user.roles ──

    public function test_show_exposes_tenant_scoped_nested_user_roles(): void
    {
        $otherTenant = $this->makeTenant('Otra Spa');

        $hybrid = $this->makeProvider(['first_name' => 'Beta', 'email' => 'beta@test.com']);
        $hybridUser = $this->makeLinkedUser($hybrid);
        $hybridUser->roles()->attach($this->roleId('staff'), ['tenant_id' => $this->tenant->id]);
        $hybridUser->roles()->attach($this->roleId('admin_local'), ['tenant_id' => $otherTenant->id]);

        $this->authenticateAs($this->admin);

        $response = $this->getJson("/api/v1/providers/{$hybrid->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.user.id', $hybridUser->id);
        $response->assertJsonPath('data.user.roles', [
            ['slug' => 'staff', 'name' => 'Staff'],
        ]);
    }

    // ── S-11: booking embedding stays byte-compatible (no user key) ──

    public function test_booking_payload_keeps_provider_embed_without_user_key(): void
    {
        $provider = $this->makeProvider(['first_name' => 'Ana', 'email' => 'ana@test.com']);
        $this->makeLinkedUser($provider);

        $service = Service::create([
            'name' => 'Masaje', 'price' => 30000, 'duration_minutes' => 60,
        ]);
        $client = Client::create([
            'first_name' => 'Test', 'last_name' => 'Client',
            'email' => 'client@test.com', 'active' => true,
        ]);
        $status = BookingStatus::create(['name' => 'Confirmed', 'is_cancellation' => false]);
        Booking::create([
            'client_id' => $client->id,
            'service_id' => $service->id,
            'provider_id' => $provider->id,
            'location_id' => $this->location->id,
            'status_id' => $status->id,
            'start_time' => Carbon::tomorrow()->addHours(10),
            'end_time' => Carbon::tomorrow()->addHours(11),
            'price' => 30000,
        ]);

        $this->authenticateAs($this->admin);

        $response = $this->getJson('/api/v1/bookings');

        $response->assertStatus(200);
        // The provider embed is present and rendered...
        $response->assertJsonPath('0.provider.id', $provider->id);
        $response->assertJsonPath('0.provider.email', $provider->email);
        // ...but booking endpoints only load `provider`, never `provider.user`,
        // so no user/roles keys may appear (byte-compatible payload).
        $response->assertJsonMissingPath('0.provider.user');
    }

    // ── Seeder fixtures (S-13..S-15) ───────────────────────────────

    /**
     * Creates one provider per ProviderCalendarRoleSeeder::EMAIL_ROLE_MAP entry,
     * each with a real linked user whose email equals the provider email
     * (prod shape: provider users carry tenant_id NULL — the user_role pivot
     * is the tenant-scoped source of truth).
     *
     * @return array<string, Provider> map email => provider
     */
    private function createRealKinesilkProviders(): array
    {
        $providers = [];

        foreach (ProviderCalendarRoleSeeder::EMAIL_ROLE_MAP as $email => $slug) {
            $provider = $this->makeProvider([
                'first_name' => ucfirst((string) strstr($email, '@', true)),
                'email' => $email,
            ]);

            User::factory()->create([
                'role' => UserRole::PROVIDER,
                'email' => $email,
                'provider_id' => $provider->id,
                'tenant_id' => null,
            ]);

            $providers[$email] = $provider;
        }

        return $providers;
    }

    // ── S-13: seeder happy path — email-keyed assignment under tenant 1 ──

    public function test_seeder_assigns_attendance_roles_to_real_kinesilk_provider_users(): void
    {
        $providers = $this->createRealKinesilkProviders();

        $this->artisan('db:seed', ['--class' => ProviderCalendarRoleSeeder::class])
            ->assertSuccessful();

        // Exactly 10 rows: 9 staff + 1 staff_readonly, all under tenant 1 (S-13).
        $this->assertDatabaseCount('user_role', 10);

        foreach (ProviderCalendarRoleSeeder::EMAIL_ROLE_MAP as $email => $slug) {
            $provider = $providers[$email];
            $user = User::where('provider_id', $provider->id)->where('email', $email)->firstOrFail();

            $this->assertDatabaseHas('user_role', [
                'user_id' => $user->id,
                'tenant_id' => $this->tenant->id,
                'role_id' => $this->roleId($slug),
            ]);
        }

        $staffCount = DB::table('user_role')
            ->where('tenant_id', $this->tenant->id)
            ->where('role_id', $this->roleId('staff'))
            ->count();
        $readonlyCount = DB::table('user_role')
            ->where('tenant_id', $this->tenant->id)
            ->where('role_id', $this->roleId('staff_readonly'))
            ->count();

        $this->assertSame(9, $staffCount);
        $this->assertSame(1, $readonlyCount);
    }

    // ── S-14: idempotent re-run — pivot state identical, no duplicates ──

    public function test_seeder_rerun_is_idempotent_and_replaces_stale_tenant_roles(): void
    {
        $providers = $this->createRealKinesilkProviders();
        $maria = User::where('email', 'maria@kinesilk.cl')->firstOrFail();

        // First run: clean assignment.
        $this->artisan('db:seed', ['--class' => ProviderCalendarRoleSeeder::class])
            ->assertSuccessful();

        $firstRun = DB::table('user_role')
            ->where('tenant_id', $this->tenant->id)
            ->orderBy('user_id')->orderBy('role_id')
            ->get(['user_id', 'role_id', 'tenant_id'])
            ->all();

        // Mutate the pivot state before the re-run: Maria (provider 1) gets a
        // stale tenant-1 role she no longer should hold, plus an unrelated
        // role under a second tenant that replace semantics must NOT touch.
        $maria->roles()->attach($this->roleId('admin_local'), ['tenant_id' => $this->tenant->id]);
        $otherTenant = $this->makeTenant('Otra Spa');
        $maria->roles()->attach($this->roleId('recepcionista'), ['tenant_id' => $otherTenant->id]);

        // Re-run must restore the exact post-first-run state under tenant 1 (S-14).
        $this->artisan('db:seed', ['--class' => ProviderCalendarRoleSeeder::class])
            ->assertSuccessful();

        // Exactly the 10 tenant-1 rows again (the stale admin_local was replaced;
        // the foreign-tenant recepcionista row legitimately remains, so the total
        // row count is 11 — tenant-1 state is what replace semantics guarantees).
        $this->assertSame(10, DB::table('user_role')->where('tenant_id', $this->tenant->id)->count());

        $secondRun = DB::table('user_role')
            ->where('tenant_id', $this->tenant->id)
            ->orderBy('user_id')->orderBy('role_id')
            ->get(['user_id', 'role_id', 'tenant_id'])
            ->all();

        $this->assertEquals($firstRun, $secondRun);

        // Maria: stale tenant-1 admin_local replaced by staff; the foreign-tenant
        // recepcionista pivot survives (replace is scoped to tenant 1).
        $this->assertDatabaseHas('user_role', [
            'user_id' => $maria->id,
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->roleId('staff'),
        ]);
        $this->assertDatabaseMissing('user_role', [
            'user_id' => $maria->id,
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->roleId('admin_local'),
        ]);
        $this->assertDatabaseHas('user_role', [
            'user_id' => $maria->id,
            'tenant_id' => $otherTenant->id,
            'role_id' => $this->roleId('recepcionista'),
        ]);
    }

    // ── S-15: junk protection — nothing created/modified, count stays 10 ──

    public function test_seeder_leaves_junk_accounts_untouched(): void
    {
        $providers = $this->createRealKinesilkProviders();
        $mariaProvider = $providers['maria@kinesilk.cl'];

        // Duplicate factory user on provider 1: different email, same provider.
        $junkDup = User::factory()->create([
            'role' => UserRole::PROVIDER,
            'email' => 'royce.langosh@example.org',
            'provider_id' => $mariaProvider->id,
            'tenant_id' => null,
        ]);

        // Junk provider 11 with a linked user whose email does NOT match the
        // provider email (email-keyed real-user rule fails).
        $junkProvider11 = $this->makeProvider([
            'first_name' => 'P', 'last_name' => 'A', 'email' => 'pa@t.com',
        ]);
        $junkUser11 = User::factory()->create([
            'role' => UserRole::PROVIDER,
            'email' => 'maggio.talon@example.net',
            'provider_id' => $junkProvider11->id,
            'tenant_id' => null,
        ]);

        // Junk provider 12 with no linked user at all.
        $this->makeProvider(['first_name' => 'P', 'last_name' => 'B', 'email' => 'pb@t.com']);

        $usersBefore = User::count();
        $providersBefore = Provider::count();

        $this->artisan('db:seed', ['--class' => ProviderCalendarRoleSeeder::class])
            ->assertSuccessful();

        // Still exactly the 10 real assignments under tenant 1 (S-15).
        $this->assertDatabaseCount('user_role', 10);
        $this->assertSame(10, DB::table('user_role')->where('tenant_id', $this->tenant->id)->count());

        // No pivot rows for any junk account.
        $this->assertDatabaseMissing('user_role', ['user_id' => $junkDup->id]);
        $this->assertDatabaseMissing('user_role', ['user_id' => $junkUser11->id]);

        // The real maria user (not the duplicate) holds the staff row.
        $mariaReal = User::where('provider_id', $mariaProvider->id)->where('email', 'maria@kinesilk.cl')->firstOrFail();
        $this->assertDatabaseHas('user_role', [
            'user_id' => $mariaReal->id,
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->roleId('staff'),
        ]);

        // Nothing created or modified (no auto user/provider creation).
        $this->assertSame($usersBefore, User::count());
        $this->assertSame($providersBefore, Provider::count());
    }

    // ── T3 guard: RoleSeeder precondition — missing roles → error, no writes ──

    public function test_seeder_reports_error_when_roles_are_missing(): void
    {
        $this->createRealKinesilkProviders();

        // Simulate a DB where RoleSeeder has never run.
        Role::query()->delete();

        $this->artisan('db:seed', ['--class' => ProviderCalendarRoleSeeder::class])
            ->expectsOutputToContain('RoleSeeder')
            ->assertExitCode(0);

        // No pivots may be written when the role precondition fails.
        $this->assertDatabaseCount('user_role', 0);
    }

    // ── S-12: provider without a linked user → user: null, no error ──

    public function test_provider_without_linked_user_shows_null_user(): void
    {
        // No linked user at all.
        $userless = $this->makeProvider(['first_name' => 'Alpha', 'email' => 'alpha@test.com']);
        $this->assertNull($userless->user);

        // Linked user holding staff under the caller tenant.
        $linked = $this->makeProvider(['first_name' => 'Beta', 'email' => 'beta@test.com']);
        $linkedUser = $this->makeLinkedUser($linked);
        $linkedUser->roles()->attach($this->roleId('staff'), ['tenant_id' => $this->tenant->id]);

        $this->authenticateAs($this->admin);

        $response = $this->getJson('/api/v1/providers');

        $response->assertStatus(200);
        $providers = $response->json();
        $this->assertCount(2, $providers);

        // Alpha has no linked user: `user` key present and null, no error.
        $this->assertSame($userless->id, $providers[0]['id']);
        $this->assertArrayHasKey('user', $providers[0]);
        $this->assertNull($providers[0]['user']);

        // Beta keeps its nested user payload.
        $this->assertSame($linked->id, $providers[1]['id']);
        $response->assertJsonPath('1.user.id', $linkedUser->id);
    }
}
