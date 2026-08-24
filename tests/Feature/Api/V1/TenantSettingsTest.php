<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Exceptions\LogoProcessingUnavailable;
use App\Models\Tenant;
use App\Models\User;
use App\Services\LogoService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

class TenantSettingsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private User $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => UserRole::ADMIN,
        ]);

        $this->provider = User::factory()->create([
            'role' => UserRole::PROVIDER,
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

    private function relativePath(string $url): string
    {
        return Str::after($url, '/storage/');
    }

    // ── GET ─────────────────────────────────────────────────────────

    public function test_get_returns_null_defaults_when_no_tenant(): void
    {
        $this->authenticateAs($this->admin);

        $response = $this->getJson('/api/v1/tenant/settings');

        $response->assertStatus(200);
        $response->assertJson([
            'business_name' => null,
            'business_rut' => null,
            'business_logo_url' => null,
        ]);
    }

    public function test_get_returns_existing_profile(): void
    {
        $this->associateTenant($this->admin, [
            'business_name' => 'Kinesilk',
            'business_rut' => '11111111-1',
            'business_logo_url' => 'http://localhost/storage/tenant-logos/old.webp',
        ]);

        $this->authenticateAs($this->admin);

        $response = $this->getJson('/api/v1/tenant/settings');

        $response->assertStatus(200);
        $response->assertJson([
            'business_name' => 'Kinesilk',
            'business_rut' => '11111111-1',
            'business_logo_url' => 'http://localhost/storage/tenant-logos/old.webp',
        ]);
    }

    // ── PATCH ───────────────────────────────────────────────────────

    public function test_patch_partial_update_preserves_other_fields(): void
    {
        $this->associateTenant($this->admin, [
            'business_name' => 'Old Name',
            'business_rut' => '11111111-1',
        ]);

        $this->authenticateAs($this->admin);

        $response = $this->patchJson('/api/v1/tenant/settings', [
            'business_name' => 'New Name',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'business_name' => 'New Name',
            'business_rut' => '11111111-1',
        ]);
    }

    public function test_patch_creates_profile_on_first_write(): void
    {
        $this->authenticateAs($this->admin);

        $response = $this->patchJson('/api/v1/tenant/settings', [
            'business_name' => 'First Write',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'business_name' => 'First Write',
            'business_rut' => null,
        ]);

        $this->assertDatabaseHas('tenants', [
            'business_name' => 'First Write',
        ]);

        $this->assertNotNull($this->admin->fresh()->tenant_id);
    }

    public function test_patch_accepts_valid_rut(): void
    {
        $this->associateTenant($this->admin);

        $this->authenticateAs($this->admin);

        $response = $this->patchJson('/api/v1/tenant/settings', [
            'business_rut' => '11111111-1',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'business_rut' => '11111111-1',
        ]);
    }

    public function test_patch_rejects_invalid_rut(): void
    {
        $this->associateTenant($this->admin);

        $this->authenticateAs($this->admin);

        $response = $this->patchJson('/api/v1/tenant/settings', [
            'business_rut' => '11111111-2',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['business_rut']);
    }

    public function test_patch_rejects_overlength_business_name(): void
    {
        $this->associateTenant($this->admin);

        $this->authenticateAs($this->admin);

        $response = $this->patchJson('/api/v1/tenant/settings', [
            'business_name' => str_repeat('a', 256),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['business_name']);
    }

    // ── Logo ────────────────────────────────────────────────────────

    public function test_logo_upload_succeeds_and_optimizes(): void
    {
        Storage::fake('public');
        $this->authenticateAs($this->admin);

        $response = $this->postJson('/api/v1/tenant/settings/logo', [
            'logo' => UploadedFile::fake()->image('logo.png', 400, 300),
        ]);

        $response->assertStatus(200);

        $url = $response->json('business_logo_url');
        $this->assertNotNull($url);

        $relative = $this->relativePath($url);
        Storage::disk('public')->assertExists($relative);

        [$width, $height] = getimagesize(Storage::disk('public')->path($relative));
        $this->assertSame(200, $width);
        $this->assertSame(150, $height);
    }

    public function test_logo_upload_rejects_invalid_mime(): void
    {
        Storage::fake('public');
        $this->authenticateAs($this->admin);

        $response = $this->postJson('/api/v1/tenant/settings/logo', [
            'logo' => UploadedFile::fake()->create('logo.txt', 100, 'text/plain'),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['logo']);
    }

    public function test_logo_upload_rejects_oversized_file(): void
    {
        Storage::fake('public');
        $this->authenticateAs($this->admin);

        $response = $this->postJson('/api/v1/tenant/settings/logo', [
            'logo' => $this->oversizedPng(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['logo']);
    }

    public function test_logo_upload_replaces_previous_file(): void
    {
        Storage::fake('public');
        $this->authenticateAs($this->admin);

        $first = $this->postJson('/api/v1/tenant/settings/logo', [
            'logo' => UploadedFile::fake()->image('logo.png', 400, 300),
        ]);
        $first->assertStatus(200);

        $firstUrl = $first->json('business_logo_url');
        $firstRelative = $this->relativePath($firstUrl);
        Storage::disk('public')->assertExists($firstRelative);

        $second = $this->postJson('/api/v1/tenant/settings/logo', [
            'logo' => UploadedFile::fake()->image('logo.png', 300, 300),
        ]);
        $second->assertStatus(200);

        $secondUrl = $second->json('business_logo_url');
        $secondRelative = $this->relativePath($secondUrl);

        $this->assertNotSame($firstUrl, $secondUrl);
        Storage::disk('public')->assertMissing($firstRelative);
        Storage::disk('public')->assertExists($secondRelative);

        $this->assertDatabaseHas('tenants', [
            'business_logo_url' => $secondUrl,
        ]);
    }

    public function test_logo_upload_returns_501_when_gd_unavailable(): void
    {
        Storage::fake('public');

        $this->mock(LogoService::class, function (MockInterface $mock) {
            $mock->shouldReceive('store')
                ->andThrow(new LogoProcessingUnavailable('GD extension is missing.'));
        });

        $this->authenticateAs($this->admin);

        $response = $this->postJson('/api/v1/tenant/settings/logo', [
            'logo' => UploadedFile::fake()->image('logo.png', 400, 300),
        ]);

        $response->assertStatus(501);
        $response->assertJson([
            'error' => 'logo_processing_unavailable',
        ]);
    }

    // ── Auth ────────────────────────────────────────────────────────

    public function test_non_admin_forbidden(): void
    {
        $this->authenticateAs($this->provider);

        $response = $this->getJson('/api/v1/tenant/settings');

        $response->assertStatus(403);
    }

    public function test_unauthenticated_returns_401(): void
    {
        $response = $this->getJson('/api/v1/tenant/settings');

        $response->assertStatus(401);
    }

    private function oversizedPng(): UploadedFile
    {
        $image = imagecreatetruecolor(1200, 1200);
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefill($image, 0, 0, $white);

        $path = tempnam(sys_get_temp_dir(), 'logo_oversize').'.png';
        imagepng($image, $path, 0);
        imagedestroy($image);

        return new UploadedFile($path, 'logo.png', 'image/png', null, true);
    }
}
