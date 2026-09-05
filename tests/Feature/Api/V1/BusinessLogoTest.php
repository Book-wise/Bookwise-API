<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class BusinessLogoTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $adminGeneral;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        // Tenant principal del admin_general.
        $this->tenant = Tenant::factory()->create();

        $this->adminGeneral = User::factory()->create(['role' => UserRole::ADMIN]);
        $role = Role::where('slug', 'admin_general')->firstOrFail();
        $this->adminGeneral->roles()->attach($role->id, ['tenant_id' => $this->tenant->id]);
    }

    private function authenticateAs(User $user): void
    {
        $this->withHeader('Authorization', 'Bearer '.$user->createToken('test-token', ['*'])->plainTextToken);
    }

    private function relativePath(string $url): string
    {
        return Str::after($url, '/storage/');
    }

    // ── POST /businesses/{id}/logo ────────────────────────────────

    public function test_upload_logo_requires_authentication(): void
    {
        Storage::fake('public');

        $this->postJson("/api/v1/businesses/{$this->tenant->id}/logo", [
            'logo' => UploadedFile::fake()->image('logo.png', 300, 300),
        ])->assertStatus(401);
    }

    public function test_upload_logo_succeeds_for_admin_general(): void
    {
        Storage::fake('public');
        $this->authenticateAs($this->adminGeneral);

        $response = $this->postJson("/api/v1/businesses/{$this->tenant->id}/logo", [
            'logo' => UploadedFile::fake()->image('logo.png', 300, 300),
        ]);

        $response->assertStatus(200);
        $url = $response->json('data.logo_url');
        $this->assertNotNull($url);

        $relative = $this->relativePath($url);
        Storage::disk('public')->assertExists($relative);

        $this->assertDatabaseHas('tenants', [
            'id' => $this->tenant->id,
            'business_logo_url' => $url,
        ]);
    }

    public function test_upload_logo_rejects_non_manager_of_tenant(): void
    {
        Storage::fake('public');

        // Admin local que gestiona OTRO tenant (no el del logo).
        $otherTenant = Tenant::factory()->create();
        $adminLocal = User::factory()->create(['role' => UserRole::ADMIN]);
        $roleLocal = Role::where('slug', 'admin_local')->firstOrFail();
        $adminLocal->roles()->attach($roleLocal->id, ['tenant_id' => $otherTenant->id]);
        $adminLocal->tenant_id = $otherTenant->id;
        $adminLocal->save();
        $this->authenticateAs($adminLocal);

        $this->postJson("/api/v1/businesses/{$this->tenant->id}/logo", [
            'logo' => UploadedFile::fake()->image('logo.png', 300, 300),
        ])->assertStatus(403);
    }

    public function test_upload_logo_rejects_missing_tenant(): void
    {
        Storage::fake('public');
        $this->authenticateAs($this->adminGeneral);

        $this->postJson('/api/v1/businesses/999999/logo', [
            'logo' => UploadedFile::fake()->image('logo.png', 300, 300),
        ])->assertStatus(404);
    }

    // ── DELETE /businesses/{id}/logo ───────────────────────────────

    public function test_remove_logo_succeeds_for_admin_general(): void
    {
        Storage::fake('public');
        // Set a logo first.
        $this->tenant->update(['business_logo_url' => '/storage/tenant-logos/x.webp']);

        $this->authenticateAs($this->adminGeneral);

        $response = $this->deleteJson("/api/v1/businesses/{$this->tenant->id}/logo");

        $response->assertStatus(200);
        $this->assertNull($response->json('data.logo_url'));
        $this->assertDatabaseHas('tenants', [
            'id' => $this->tenant->id,
            'business_logo_url' => null,
        ]);
    }

    public function test_remove_logo_rejects_non_manager_of_tenant(): void
    {
        Storage::fake('public');
        $this->tenant->update(['business_logo_url' => '/storage/tenant-logos/x.webp']);

        $otherTenant = Tenant::factory()->create();
        $adminLocal = User::factory()->create(['role' => UserRole::ADMIN]);
        $roleLocal = Role::where('slug', 'admin_local')->firstOrFail();
        $adminLocal->roles()->attach($roleLocal->id, ['tenant_id' => $otherTenant->id]);
        $adminLocal->tenant_id = $otherTenant->id;
        $adminLocal->save();
        $this->authenticateAs($adminLocal);

        $this->deleteJson("/api/v1/businesses/{$this->tenant->id}/logo")->assertStatus(403);
    }
}
