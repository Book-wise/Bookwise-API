<?php

namespace Tests\Feature\Api\V1;

use App\Enums\BusinessRole;
use App\Enums\UserRole;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RolesTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function authenticateAs(User $user): void
    {
        $this->withHeader('Authorization', 'Bearer '.$user->createToken('test-token', ['*'])->plainTextToken);
    }

    public function test_seeder_creates_exactly_six_business_roles(): void
    {
        $this->seed(RoleSeeder::class);

        $this->assertDatabaseCount('roles', 6);

        $this->assertSame(
            BusinessRole::values(),
            Role::orderBy('id')->pluck('slug')->all()
        );
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->assertDatabaseCount('roles', 6);
        $this->assertSame(6, Role::pluck('slug')->unique()->count());
    }

    public function test_roles_table_has_unique_slug_constraint(): void
    {
        $this->assertTrue(Schema::hasIndex('roles', 'roles_slug_unique'));
    }

    // ── S21: GET /roles — global business-role catalog (R11.1) ───────

    public function test_get_roles_returns_the_six_seeded_roles_in_order(): void
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $this->authenticateAs($admin);

        $response = $this->getJson('/api/v1/roles');

        $response->assertStatus(200);
        $response->assertJsonStructure(['data' => [['slug', 'name']]]);
        $response->assertJsonCount(6, 'data');

        $slugs = collect($response->json('data'))->pluck('slug')->all();
        $this->assertSame(BusinessRole::values(), $slugs);

        $response->assertJsonPath('data.0.slug', 'admin_general');
        $response->assertJsonPath('data.0.name', 'Administrador General');
        $response->assertJsonPath('data.5.slug', 'staff_readonly');
        $response->assertJsonPath('data.5.name', 'Staff (solo lectura)');
    }

    public function test_get_roles_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/roles');

        $response->assertStatus(401);
    }

    public function test_get_roles_rejects_non_admin(): void
    {
        $provider = User::factory()->create(['role' => UserRole::PROVIDER]);
        $this->authenticateAs($provider);

        $response = $this->getJson('/api/v1/roles');

        $response->assertStatus(403);
        $response->assertJson(['error' => 'forbidden']);
    }
}
