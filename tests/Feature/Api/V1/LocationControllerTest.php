<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\BookingStatus;
use App\Models\Client;
use App\Models\Comuna;
use App\Models\Location;
use App\Models\Region;
use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class LocationControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private Region $region;

    private Client $client;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->region = Region::create([
            'name' => 'Metropolitana',
            'timezone' => 'America/Santiago',
            'sort_order' => 7,
        ]);

        $this->admin = User::factory()->create([
            'role' => UserRole::ADMIN,
        ]);

        $this->client = Client::create([
            'first_name' => 'Test',
            'last_name' => 'Client',
            'email' => 'test@example.com',
            'active' => true,
        ]);

        $this->service = Service::create([
            'name' => 'Test Service',
            'price' => 50000,
            'duration_minutes' => 60,
        ]);
    }

    private function authenticate(): void
    {
        $token = $this->admin->createToken('test-token', ['*']);
        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken);
    }

    // ── POST /v1/locations (task 2.8) ─────────────────────────────

    public function test_store_returns_201_with_valid_data(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/v1/locations', [
            'name' => 'Sucursal Centro',
            'address' => 'Av. Providencia 1234',
            'city' => 'Santiago',
            'region_id' => $this->region->id,
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'message' => 'Sucursal creada exitosamente',
        ]);
        $response->assertJsonPath('data.name', 'Sucursal Centro');
        $response->assertJsonPath('data.timezone', 'America/Santiago');
        $response->assertJsonPath('data.active', true);
        $response->assertJsonStructure([
            'data' => [
                'id', 'name', 'address', 'city', 'timezone',
                'active', 'opening_time', 'closing_time',
                'region' => ['id', 'name', 'timezone'],
            ],
            'message',
        ]);
    }

    public function test_store_returns_422_duplicate_name(): void
    {
        $this->authenticate();

        Location::create([
            'name' => 'Sucursal Centro',
            'region_id' => $this->region->id,
            'timezone' => 'America/Santiago',
        ]);

        $response = $this->postJson('/api/v1/locations', [
            'name' => 'Sucursal Centro',
            'region_id' => $this->region->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    }

    public function test_store_returns_422_invalid_region_id(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/v1/locations', [
            'name' => 'Sucursal Test',
            'region_id' => 99999,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['region_id']);
    }

    public function test_store_returns_422_comuna_not_in_region(): void
    {
        $this->authenticate();

        $otherRegion = Region::create([
            'name' => 'Valparaíso',
            'timezone' => 'America/Santiago',
            'sort_order' => 6,
        ]);

        $comuna = Comuna::create([
            'region_id' => $otherRegion->id,
            'name' => 'Viña del Mar',
        ]);

        $response = $this->postJson('/api/v1/locations', [
            'name' => 'Sucursal Test',
            'region_id' => $this->region->id,
            'comuna_id' => $comuna->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['comuna_id']);
    }

    public function test_store_requires_authentication(): void
    {
        $response = $this->postJson('/api/v1/locations', [
            'name' => 'Sucursal Centro',
            'region_id' => $this->region->id,
        ]);

        $response->assertStatus(401);
    }

    // ── PATCH /v1/locations/{id} (task 2.9) ───────────────────────

    public function test_update_returns_200_partial_update(): void
    {
        $this->authenticate();

        $location = Location::create([
            'name' => 'Sucursal Original',
            'region_id' => $this->region->id,
            'timezone' => 'America/Santiago',
            'active' => true,
        ]);

        $response = $this->patchJson("/api/v1/locations/{$location->id}", [
            'address' => 'Nueva Dirección 567',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Sucursal actualizada exitosamente',
        ]);
        $response->assertJsonPath('data.address', 'Nueva Dirección 567');
    }

    public function test_update_returns_200_activate_location(): void
    {
        $this->authenticate();

        $location = Location::create([
            'name' => 'Sucursal Inactiva',
            'region_id' => $this->region->id,
            'timezone' => 'America/Santiago',
            'active' => false,
        ]);

        $response = $this->patchJson("/api/v1/locations/{$location->id}", [
            'active' => true,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.active', true);
    }

    public function test_update_returns_409_deactivate_with_conflicts(): void
    {
        $this->authenticate();

        $location = Location::create([
            'name' => 'Sucursal Con Reservas',
            'region_id' => $this->region->id,
            'timezone' => 'America/Santiago',
            'active' => true,
        ]);

        $status = BookingStatus::create([
            'name' => 'Confirmed',
            'is_cancellation' => false,
            'is_finalized' => false,
        ]);

        Booking::create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'location_id' => $location->id,
            'status_id' => $status->id,
            'start_time' => Carbon::tomorrow()->addHours(10),
            'end_time' => Carbon::tomorrow()->addHours(11),
            'price' => 50000,
        ]);

        $response = $this->patchJson("/api/v1/locations/{$location->id}", [
            'active' => false,
        ]);

        $response->assertStatus(409);
        $response->assertJson([
            'error' => 'deactivation_conflict',
            'requires_confirmation' => true,
        ]);
        $response->assertJsonStructure([
            'affects' => [
                'bookings' => [
                    '*' => ['id', 'date', 'time', 'status'],
                ],
            ],
        ]);
    }

    public function test_update_returns_200_force_deactivate_with_conflicts(): void
    {
        $this->authenticate();

        $location = Location::create([
            'name' => 'Sucursal Force',
            'region_id' => $this->region->id,
            'timezone' => 'America/Santiago',
            'active' => true,
        ]);

        $status = BookingStatus::create([
            'name' => 'Confirmed',
            'is_cancellation' => false,
            'is_finalized' => false,
        ]);

        Booking::create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'location_id' => $location->id,
            'status_id' => $status->id,
            'start_time' => Carbon::tomorrow()->addHours(10),
            'end_time' => Carbon::tomorrow()->addHours(11),
            'price' => 50000,
        ]);

        $response = $this->patchJson("/api/v1/locations/{$location->id}", [
            'active' => false,
            'force' => true,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.active', false);
        $response->assertJson([
            'message' => 'Sucursal desactivada. Las reservas existentes no se verán afectadas.',
        ]);
    }

    public function test_update_returns_200_deactivate_without_conflicts(): void
    {
        $this->authenticate();

        $location = Location::create([
            'name' => 'Sucursal Sin Reservas',
            'region_id' => $this->region->id,
            'timezone' => 'America/Santiago',
            'active' => true,
        ]);

        $response = $this->patchJson("/api/v1/locations/{$location->id}", [
            'active' => false,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.active', false);
    }

    public function test_update_requires_admin_role(): void
    {
        $provider = User::factory()->create([
            'role' => UserRole::PROVIDER,
        ]);
        $token = $provider->createToken('test-token', ['bookings:write']);
        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken);

        $location = Location::create([
            'name' => 'Sucursal Test',
            'region_id' => $this->region->id,
            'timezone' => 'America/Santiago',
        ]);

        $response = $this->patchJson("/api/v1/locations/{$location->id}", [
            'address' => 'Nueva Dirección',
        ]);

        $response->assertStatus(403);
    }
}
