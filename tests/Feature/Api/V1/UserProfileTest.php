<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Exceptions\AvatarProcessingUnavailable;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AvatarService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

class UserProfileTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Factory default is a verified user (email_verified_at = now()).
        $this->user = User::factory()->create([
            'role' => UserRole::ADMIN,
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

    // ── S20: /auth/me with tenant → onboarding_complete true + business ──

    public function test_auth_me_returns_onboarding_complete_true_with_business_when_onboarded(): void
    {
        $tenant = $this->associateTenant($this->user, [
            'business_name' => 'Kinesilk Spa',
            'business_rut' => '11111111-1',
            'business_email' => 'negocio@kinesilk.cl',
            'business_address' => 'Av. Providencia 1234',
            'business_phone' => '+56223456789',
            'business_plan' => 'starter',
            'business_logo_url' => 'http://localhost/storage/tenant-logos/logo.webp',
        ]);

        $this->authenticateAs($this->user);

        $response = $this->getJson('/api/v1/auth/me');

        $response->assertStatus(200);
        $response->assertJsonPath('user.id', $this->user->id);
        $response->assertJsonPath('user.name', $this->user->name);
        $response->assertJsonPath('user.email', $this->user->email);
        $response->assertJsonPath('user.phone', $this->user->phone);
        $response->assertJsonPath('user.role', 'admin');
        $response->assertJsonPath('user.provider_id', null);
        $response->assertJsonPath('user.tenant_id', $tenant->id);
        $response->assertJsonPath('user.email_verified_at', $this->user->email_verified_at->toIso8601String());
        $response->assertJsonPath('user.onboarding_complete', true);
        $response->assertJsonPath('user.business.id', $tenant->id);
        $response->assertJsonPath('user.business.name', 'Kinesilk Spa');
        $response->assertJsonPath('user.business.rut', '11111111-1');
        $response->assertJsonPath('user.business.email', 'negocio@kinesilk.cl');
        $response->assertJsonPath('user.business.address', 'Av. Providencia 1234');
        $response->assertJsonPath('user.business.phone', '+56223456789');
        $response->assertJsonPath('user.business.plan', 'starter');
        $response->assertJsonPath('user.business.logo_url', 'http://localhost/storage/tenant-logos/logo.webp');
    }

    // ── S20: /auth/me without tenant → onboarding_complete false + business null ──

    public function test_auth_me_returns_onboarding_complete_false_with_null_business_without_tenant(): void
    {
        $this->authenticateAs($this->user);

        $response = $this->getJson('/api/v1/auth/me');

        $response->assertStatus(200);
        $response->assertJsonPath('user.tenant_id', null);
        $response->assertJsonPath('user.onboarding_complete', false);
        $response->assertJsonPath('user.business', null);
    }

    // ── 401 unauthenticated ──────────────────────────────────────────────

    public function test_auth_me_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertStatus(401);
    }

    // ── Avatar ────────────────────────────────────────────────────────────

    public function test_avatar_upload_succeeds_and_optimizes(): void
    {
        Storage::fake('public');
        $this->authenticateAs($this->user);

        $response = $this->postJson('/api/v1/auth/me/avatar', [
            'avatar' => UploadedFile::fake()->image('avatar.png', 300, 300),
        ]);

        $response->assertStatus(200);

        $url = $response->json('user.avatar_url');
        $this->assertNotNull($url);

        $relative = $this->relativePath($url);
        Storage::disk('public')->assertExists($relative);

        [$width, $height] = getimagesize(Storage::disk('public')->path($relative));
        // Compact thumbnail: longest side capped at AvatarService::MAX_DIMENSION.
        $this->assertSame(128, $width);
        $this->assertSame(128, $height);

        $this->assertDatabaseHas('users', [
            'id' => $this->user->id,
            'avatar_url' => $url,
        ]);
    }

    public function test_avatar_upload_rejects_invalid_mime(): void
    {
        Storage::fake('public');
        $this->authenticateAs($this->user);

        $response = $this->postJson('/api/v1/auth/me/avatar', [
            'avatar' => UploadedFile::fake()->create('avatar.txt', 100, 'text/plain'),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['avatar']);
        $this->assertNull($this->user->fresh()->avatar_url);
    }

    public function test_avatar_upload_rejects_oversized_file(): void
    {
        Storage::fake('public');
        $this->authenticateAs($this->user);

        $response = $this->postJson('/api/v1/auth/me/avatar', [
            'avatar' => $this->oversizedPng(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['avatar']);
    }

    public function test_avatar_upload_replaces_previous_file(): void
    {
        Storage::fake('public');
        $this->authenticateAs($this->user);

        $first = $this->postJson('/api/v1/auth/me/avatar', [
            'avatar' => UploadedFile::fake()->image('avatar.png', 300, 300),
        ]);
        $first->assertStatus(200);

        $firstUrl = $first->json('user.avatar_url');
        $firstRelative = $this->relativePath($firstUrl);
        Storage::disk('public')->assertExists($firstRelative);

        $second = $this->postJson('/api/v1/auth/me/avatar', [
            'avatar' => UploadedFile::fake()->image('avatar.png', 200, 200),
        ]);
        $second->assertStatus(200);

        $secondUrl = $second->json('user.avatar_url');
        $secondRelative = $this->relativePath($secondUrl);

        $this->assertNotSame($firstUrl, $secondUrl);
        Storage::disk('public')->assertMissing($firstRelative);
        Storage::disk('public')->assertExists($secondRelative);

        $this->assertDatabaseHas('users', [
            'id' => $this->user->id,
            'avatar_url' => $secondUrl,
        ]);
    }

    public function test_avatar_upload_returns_501_when_gd_unavailable(): void
    {
        Storage::fake('public');

        $this->mock(AvatarService::class, function (MockInterface $mock) {
            $mock->shouldReceive('store')
                ->andThrow(new AvatarProcessingUnavailable('GD extension is missing.'));
        });

        $this->authenticateAs($this->user);

        $response = $this->postJson('/api/v1/auth/me/avatar', [
            'avatar' => UploadedFile::fake()->image('avatar.png', 300, 300),
        ]);

        $response->assertStatus(501);
        $response->assertJson([
            'error' => 'avatar_processing_unavailable',
        ]);
    }

    public function test_avatar_upload_requires_authentication(): void
    {
        Storage::fake('public');

        $response = $this->postJson('/api/v1/auth/me/avatar', [
            'avatar' => UploadedFile::fake()->image('avatar.png', 300, 300),
        ]);

        $response->assertStatus(401);
    }

    // ── DELETE /auth/me/avatar (remover avatar → fallback) ─────────────

    public function test_avatar_remove_deletes_file_and_nulls_url(): void
    {
        Storage::fake('public');
        $this->authenticateAs($this->user);

        // Seed: upload a first avatar.
        $upload = $this->postJson('/api/v1/auth/me/avatar', [
            'avatar' => UploadedFile::fake()->image('avatar.png', 300, 300),
        ]);
        $upload->assertStatus(200);

        $url = $upload->json('user.avatar_url');
        $this->assertNotNull($url);
        $relative = $this->relativePath($url);
        Storage::disk('public')->assertExists($relative);

        // Remove it.
        $response = $this->deleteJson('/api/v1/auth/me/avatar');

        $response->assertStatus(200);
        $response->assertJsonPath('user.avatar_url', null);

        Storage::disk('public')->assertMissing($relative);
        $this->assertDatabaseHas('users', [
            'id' => $this->user->id,
            'avatar_url' => null,
        ]);
    }

    public function test_avatar_remove_is_noop_without_avatar(): void
    {
        Storage::fake('public');
        $this->authenticateAs($this->user);

        $response = $this->deleteJson('/api/v1/auth/me/avatar');

        $response->assertStatus(200);
        $response->assertJsonPath('user.avatar_url', null);
        $this->assertNull($this->user->fresh()->avatar_url);
    }

    public function test_avatar_remove_requires_authentication(): void
    {
        Storage::fake('public');

        $response = $this->deleteJson('/api/v1/auth/me/avatar');

        $response->assertStatus(401);
    }

    private function oversizedPng(): UploadedFile
    {
        $image = imagecreatetruecolor(1200, 1200);
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefill($image, 0, 0, $white);

        $path = tempnam(sys_get_temp_dir(), 'avatar_oversize').'.png';
        imagepng($image, $path, 0);
        imagedestroy($image);

        return new UploadedFile($path, 'avatar.png', 'image/png', null, true);
    }
}
