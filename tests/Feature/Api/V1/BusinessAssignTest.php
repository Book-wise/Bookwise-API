<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class BusinessAssignTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $adminGeneral;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->tenant = Tenant::factory()->create();

        // Admin general: usuario técnico admin + rol de negocio admin_general en el tenant.
        $this->adminGeneral = User::factory()->create(['role' => UserRole::ADMIN]);
        $role = Role::where('slug', 'admin_general')->firstOrFail();
        $this->adminGeneral->roles()->attach($role->id, ['tenant_id' => $this->tenant->id]);
    }

    private function authenticateAs(User $user): void
    {
        $this->withHeader('Authorization', 'Bearer '.$user->createToken('test-token', ['*'])->plainTextToken);
    }

    private function roleId(string $slug): int
    {
        return Role::where('slug', $slug)->value('id');
    }

    public function test_assign_admin_local_requires_authentication(): void
    {
        $target = User::factory()->create(['role' => UserRole::ADMIN]);

        $this->postJson("/api/v1/businesses/{$this->tenant->id}/assign-admin-local", [
            'user_id' => $target->id,
        ])->assertStatus(401);
    }

    public function test_assign_admin_local_rejects_non_admin_general(): void
    {
        $other = User::factory()->create(['role' => UserRole::ADMIN]);
        $this->authenticateAs($other);

        $target = User::factory()->create(['role' => UserRole::ADMIN]);

        $this->postJson("/api/v1/businesses/{$this->tenant->id}/assign-admin-local", [
            'user_id' => $target->id,
        ])->assertStatus(403);
    }

    public function test_assign_admin_local_succeeds_and_is_idempotent(): void
    {
        $this->authenticateAs($this->adminGeneral);
        $target = User::factory()->create(['role' => UserRole::ADMIN]);

        $response = $this->postJson("/api/v1/businesses/{$this->tenant->id}/assign-admin-local", [
            'user_id' => $target->id,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.role', 'admin_local');
        $this->assertDatabaseHas('user_role', [
            'user_id' => $target->id,
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->roleId('admin_local'),
        ]);

        // Second call does not duplicate (unique user+tenant+role).
        $this->postJson("/api/v1/businesses/{$this->tenant->id}/assign-admin-local", [
            'user_id' => $target->id,
        ])->assertStatus(200);

        $this->assertDatabaseCount('user_role', 2);
    }

    public function test_assign_admin_local_rejects_missing_user(): void
    {
        $this->authenticateAs($this->adminGeneral);

        $this->postJson("/api/v1/businesses/{$this->tenant->id}/assign-admin-local", [
            'user_id' => 999999,
        ])->assertStatus(422);
    }

    public function test_assign_admin_local_rejects_missing_tenant(): void
    {
        $this->authenticateAs($this->adminGeneral);
        $target = User::factory()->create(['role' => UserRole::ADMIN]);

        $this->postJson('/api/v1/businesses/999999/assign-admin-local', [
            'user_id' => $target->id,
        ])->assertStatus(404);
    }

    public function test_unassign_admin_local_succeeds(): void
    {
        $this->authenticateAs($this->adminGeneral);
        $target = User::factory()->create(['role' => UserRole::ADMIN]);

        // Assign first.
        $this->postJson("/api/v1/businesses/{$this->tenant->id}/assign-admin-local", [
            'user_id' => $target->id,
        ])->assertStatus(200);

        // Unassign.
        $response = $this->deleteJson("/api/v1/businesses/{$this->tenant->id}/assign-admin-local", [
            'user_id' => $target->id,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.role', 'admin_local');
        $this->assertDatabaseMissing('user_role', [
            'user_id' => $target->id,
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->roleId('admin_local'),
        ]);
    }

    public function test_unassign_admin_local_when_not_assigned_does_not_fail(): void
    {
        $this->authenticateAs($this->adminGeneral);
        $target = User::factory()->create(['role' => UserRole::ADMIN]);

        $this->deleteJson("/api/v1/businesses/{$this->tenant->id}/assign-admin-local", [
            'user_id' => $target->id,
        ])->assertStatus(200);

        $this->assertDatabaseMissing('user_role', [
            'user_id' => $target->id,
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->roleId('admin_local'),
        ]);
    }

    public function test_unassign_admin_local_rejects_non_admin_general(): void
    {
        $other = User::factory()->create(['role' => UserRole::ADMIN]);
        $this->authenticateAs($other);
        $target = User::factory()->create(['role' => UserRole::ADMIN]);

        $this->deleteJson("/api/v1/businesses/{$this->tenant->id}/assign-admin-local", [
            'user_id' => $target->id,
        ])->assertStatus(403);
    }
}
