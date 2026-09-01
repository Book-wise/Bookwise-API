<?php

namespace Tests\Feature\Api\V1;

use App\Enums\BusinessRole;
use App\Enums\UserRole;
use App\Models\EmailVerificationToken;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OnboardingSchemaTest extends TestCase
{
    use LazilyRefreshDatabase;

    // ── M1: users.phone + legacy email_verified_at backfill (R1) ────

    public function test_users_table_has_nullable_phone_column(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'phone'));

        $column = collect(Schema::getColumns('users'))->firstWhere('name', 'phone');
        $this->assertNotNull($column, 'users.phone column missing from schema');
        $this->assertTrue($column['nullable'], 'users.phone must be nullable');
    }

    public function test_phone_migration_backfill_only_verifies_legacy_unverified_users(): void
    {
        // Approval test of M1's whereNull guard (R1.2): the exact DML the
        // migration runs must verify legacy users and never touch verified ones.
        $originalVerifiedAt = now()->subDays(5);

        User::factory()->create([
            'email' => 'legacy@example.com',
            'role' => UserRole::ADMIN,
            'email_verified_at' => null,
        ]);
        User::factory()->create([
            'email' => 'verified@example.com',
            'role' => UserRole::ADMIN,
            'email_verified_at' => $originalVerifiedAt,
        ]);

        DB::table('users')->whereNull('email_verified_at')->update(['email_verified_at' => now()]);

        $this->assertNotNull(User::where('email', 'legacy@example.com')->first()->email_verified_at);
        $this->assertSame(
            $originalVerifiedAt->toDateTimeString(),
            User::where('email', 'verified@example.com')->first()->email_verified_at->toDateTimeString()
        );
    }

    // ── M2: tenants onboarding columns + unique business_rut (R2) ───

    public function test_tenants_table_has_onboarding_columns_and_unique_rut_index(): void
    {
        foreach (['business_email', 'business_address', 'business_phone', 'business_plan'] as $column) {
            $this->assertTrue(Schema::hasColumn('tenants', $column), "tenants.{$column} missing");
        }

        $this->assertTrue(Schema::hasIndex('tenants', 'tenants_business_rut_unique'));
    }

    public function test_tenants_business_rut_unique_index_rejects_duplicates(): void
    {
        Tenant::factory()->create(['business_rut' => '11111111-1']);

        $this->expectException(QueryException::class);

        Tenant::factory()->create(['business_rut' => '11111111-1']);
    }

    public function test_tenants_business_rut_unique_index_allows_multiple_null_ruts(): void
    {
        Tenant::factory()->create(['business_rut' => null]);
        Tenant::factory()->create(['business_rut' => null]);

        $this->assertDatabaseCount('tenants', 2);
    }

    // ── M3: email_verification_tokens (R4) ──────────────────────────

    public function test_email_verification_tokens_table_schema(): void
    {
        $this->assertTrue(Schema::hasTable('email_verification_tokens'));

        foreach (['user_id', 'token_hash', 'expires_at', 'used_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('email_verification_tokens', $column), "email_verification_tokens.{$column} missing");
        }

        $this->assertTrue(Schema::hasIndex('email_verification_tokens', 'email_verification_tokens_token_hash_unique'));
        $this->assertTrue(Schema::hasIndex('email_verification_tokens', 'email_verification_tokens_user_id_index'));
    }

    public function test_verification_tokens_cascade_delete_when_user_is_deleted(): void
    {
        $user = User::factory()->create(['role' => UserRole::ADMIN]);
        $token = EmailVerificationToken::factory()->create(['user_id' => $user->id]);

        $user->delete();

        $this->assertDatabaseMissing('email_verification_tokens', ['id' => $token->id]);
    }

    public function test_verification_token_hash_is_unique(): void
    {
        $hash = hash('sha256', 'same-plain-token');
        EmailVerificationToken::factory()->create(['token_hash' => $hash]);

        $this->expectException(QueryException::class);

        EmailVerificationToken::factory()->create(['token_hash' => $hash]);
    }

    // ── M4: roles (R3.1) ────────────────────────────────────────────

    public function test_roles_table_schema(): void
    {
        $this->assertTrue(Schema::hasTable('roles'));

        foreach (['name', 'slug'] as $column) {
            $this->assertTrue(Schema::hasColumn('roles', $column), "roles.{$column} missing");
        }

        $this->assertTrue(Schema::hasIndex('roles', 'roles_slug_unique'));
    }

    // ── M5: user_role pivot (R3.3) ──────────────────────────────────

    public function test_user_role_table_schema_and_unique_triple(): void
    {
        $this->assertTrue(Schema::hasTable('user_role'));

        foreach (['user_id', 'role_id', 'tenant_id'] as $column) {
            $this->assertTrue(Schema::hasColumn('user_role', $column), "user_role.{$column} missing");
        }

        $this->assertTrue(Schema::hasIndex('user_role', 'user_role_user_tenant_role_unique'));
    }

    public function test_user_role_unique_triple_rejects_duplicates(): void
    {
        $user = User::factory()->create(['role' => UserRole::ADMIN]);
        $tenant = Tenant::factory()->create();
        $role = Role::create(['name' => 'Staff', 'slug' => 'staff']);

        $user->roles()->attach($role->id, ['tenant_id' => $tenant->id]);

        $this->expectException(QueryException::class);

        $user->roles()->attach($role->id, ['tenant_id' => $tenant->id]);
    }

    public function test_user_role_cascades_when_role_or_tenant_is_deleted(): void
    {
        $user = User::factory()->create(['role' => UserRole::ADMIN]);
        $tenant = Tenant::factory()->create();
        $role = Role::create(['name' => 'Staff', 'slug' => 'staff']);
        $user->roles()->attach($role->id, ['tenant_id' => $tenant->id]);

        $role->delete();
        $this->assertDatabaseMissing('user_role', ['user_id' => $user->id, 'role_id' => $role->id]);

        $secondRole = Role::create(['name' => 'Admin General', 'slug' => 'admin_general']);
        $user->roles()->attach($secondRole->id, ['tenant_id' => $tenant->id]);

        $tenant->delete();
        $this->assertDatabaseCount('user_role', 0);
    }

    // ── Models, enum, factories (R1.3, R2.3, R3.4, R4.4, D5) ───────

    public function test_business_role_enum_defines_exactly_six_slugs(): void
    {
        $this->assertSame([
            'admin_general',
            'admin_local',
            'recepcionista',
            'recepcionista_readonly',
            'staff',
            'staff_readonly',
        ], BusinessRole::values());
    }

    public function test_user_model_exposes_phone_and_verified_helper(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::ADMIN,
            'phone' => '+56 9 1234 5678',
        ]);

        $this->assertSame('+56 9 1234 5678', $user->phone);
        $this->assertTrue($user->isVerified());

        $unverified = User::factory()->create([
            'role' => UserRole::ADMIN,
            'email_verified_at' => null,
        ]);
        $this->assertFalse($unverified->isVerified());
    }

    public function test_user_factory_generates_phone(): void
    {
        $user = User::factory()->create(['role' => UserRole::ADMIN]);

        $this->assertNotNull($user->phone);
    }

    public function test_user_roles_belongs_to_many_carries_tenant_pivot(): void
    {
        $user = User::factory()->create(['role' => UserRole::ADMIN]);
        $tenant = Tenant::factory()->create();
        $role = Role::create(['name' => 'Recepcionista', 'slug' => 'recepcionista']);

        $user->roles()->attach($role->id, ['tenant_id' => $tenant->id]);

        $this->assertDatabaseHas('user_role', [
            'user_id' => $user->id,
            'role_id' => $role->id,
            'tenant_id' => $tenant->id,
        ]);

        $this->assertEquals(
            $tenant->id,
            $user->roles()->wherePivot('tenant_id', $tenant->id)->first()->pivot->tenant_id
        );
    }

    public function test_tenant_roles_relationship(): void
    {
        $user = User::factory()->create(['role' => UserRole::ADMIN]);
        $tenant = Tenant::factory()->create();
        $role = Role::create(['name' => 'Staff', 'slug' => 'staff']);

        $tenant->roles()->attach($role->id, ['user_id' => $user->id]);

        $this->assertTrue($tenant->roles->contains($role));
    }

    public function test_role_assignment_pivot_model_resolves_relations(): void
    {
        $user = User::factory()->create(['role' => UserRole::ADMIN]);
        $tenant = Tenant::factory()->create();
        $role = Role::create(['name' => 'Staff', 'slug' => 'staff']);

        $assignment = RoleAssignment::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'tenant_id' => $tenant->id,
        ]);

        $this->assertTrue($assignment->user->is($user));
        $this->assertTrue($assignment->role->is($role));
        $this->assertTrue($assignment->tenant->is($tenant));
    }

    public function test_email_verification_token_model_casts_dates_and_resolves_user(): void
    {
        $user = User::factory()->create(['role' => UserRole::ADMIN]);
        $token = EmailVerificationToken::factory()->create([
            'user_id' => $user->id,
            'expires_at' => now()->addHours(48),
        ]);

        $this->assertInstanceOf(Carbon::class, $token->expires_at);
        $this->assertNull($token->used_at);
        $this->assertTrue($token->user->is($user));
    }

    public function test_tenant_factory_sets_starter_plan_and_nullable_columns(): void
    {
        $tenant = Tenant::factory()->create();

        $this->assertSame('starter', $tenant->business_plan);
        $this->assertNull($tenant->business_email);
        $this->assertNull($tenant->business_address);
        $this->assertNull($tenant->business_phone);
    }

    // ── Config (R2.4, D1) ───────────────────────────────────────────

    public function test_frontend_url_config_block_is_registered(): void
    {
        $this->assertIsArray(config('services.frontend'));
        $this->assertArrayHasKey('url', config('services.frontend'));
    }
}
