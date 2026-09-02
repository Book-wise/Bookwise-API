<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\BookingStatus;
use App\Models\Client;
use App\Models\Location;
use App\Models\Provider;
use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ProviderDeactivationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private Client $client;

    private Service $service;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        // Factory default is a verified admin (email_verified_at = now()).
        $this->admin = User::factory()->create(['role' => UserRole::ADMIN]);

        $this->client = Client::create([
            'first_name' => 'Test',
            'last_name' => 'Client',
            'email' => 'client@test.com',
            'active' => true,
        ]);

        $this->service = Service::create([
            'name' => 'Test Service',
            'price' => 50000,
            'duration_minutes' => 60,
        ]);

        $this->location = Location::create([
            'name' => 'Test Location',
            'address' => '123 Test St',
        ]);
    }

    private function authenticate(): void
    {
        $token = $this->admin->createToken('test-token', ['*']);
        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken);
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

    private function makeLiveStatus(string $name = 'Confirmed'): BookingStatus
    {
        return BookingStatus::create([
            'name' => $name,
            'is_cancellation' => false,
            'is_finalized' => false,
        ]);
    }

    private function createFutureBooking(Provider $provider, Client $client, BookingStatus $status): Booking
    {
        return Booking::create([
            'client_id' => $client->id,
            'service_id' => $this->service->id,
            'provider_id' => $provider->id,
            'location_id' => $this->location->id,
            'status_id' => $status->id,
            'start_time' => Carbon::tomorrow()->addHours(10),
            'end_time' => Carbon::tomorrow()->addHours(11),
            'price' => 50000,
        ]);
    }

    // ── S-P7: auth / authorization / not found gates ──────────────

    public function test_update_requires_authentication(): void
    {
        $provider = $this->makeProvider();

        $response = $this->patchJson("/api/v1/providers/{$provider->id}", [
            'active' => false,
        ]);

        $response->assertStatus(401);
    }

    public function test_update_rejects_non_admin_role(): void
    {
        $provider = $this->makeProvider();

        $providerUser = User::factory()->create(['role' => UserRole::PROVIDER]);
        $token = $providerUser->createToken('test-token', ['providers:write']);
        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken);

        $response = $this->patchJson("/api/v1/providers/{$provider->id}", [
            'active' => false,
        ]);

        $response->assertStatus(403);
        $response->assertJson(['error' => 'forbidden']);
    }

    public function test_update_unknown_provider_returns_404(): void
    {
        $this->authenticate();

        $response = $this->patchJson('/api/v1/providers/99999', [
            'active' => false,
        ]);

        $response->assertStatus(404);
    }

    // ── S-P2: deactivation conflict blocks with 409 ────────────────

    public function test_update_returns_409_when_deactivating_with_future_live_bookings(): void
    {
        $this->authenticate();

        $provider = $this->makeProvider();
        $booking = $this->createFutureBooking($provider, $this->client, $this->makeLiveStatus());

        $response = $this->patchJson("/api/v1/providers/{$provider->id}", [
            'active' => false,
        ]);

        $response->assertStatus(409);
        $response->assertJson([
            'error' => 'deactivation_conflict',
            'message' => 'El profesional tiene 1 reservas futuras por atender. Reubica o cancela sus reservas antes de desactivarlo.',
            'requires_confirmation' => true,
        ]);
        $response->assertJsonStructure([
            'affects' => [
                'bookings' => [
                    '*' => ['id', 'date', 'time', 'client_name', 'status'],
                ],
            ],
        ]);
        $response->assertJsonPath('affects.bookings.0.id', $booking->id);
        $response->assertJsonPath('affects.bookings.0.client_name', 'Test Client');
        $response->assertJsonPath('affects.bookings.0.status', 'Confirmed');
        $response->assertJsonPath('affects.bookings.0.date', Carbon::tomorrow()->toDateString());
        $response->assertJsonPath('affects.bookings.0.time', Carbon::tomorrow()->addHours(10)->format('H:i'));

        $this->assertDatabaseHas('providers', ['id' => $provider->id, 'active' => 1]);
    }

    public function test_update_message_counts_multiple_conflicts_ordered_asc(): void
    {
        $this->authenticate();

        $provider = $this->makeProvider();
        $status = $this->makeLiveStatus();

        $clientA = Client::create([
            'first_name' => 'Ana',
            'last_name' => 'Pérez',
            'email' => 'ana@test.com',
            'active' => true,
        ]);
        $clientB = Client::create([
            'first_name' => 'Luis',
            'last_name' => 'Soto',
            'email' => 'luis@test.com',
            'active' => true,
        ]);

        // Two future live bookings; the earlier one (10:00) must come first.
        $bookingAtNoon = Booking::create([
            'client_id' => $clientA->id,
            'service_id' => $this->service->id,
            'provider_id' => $provider->id,
            'location_id' => $this->location->id,
            'status_id' => $status->id,
            'start_time' => Carbon::tomorrow()->addHours(12),
            'end_time' => Carbon::tomorrow()->addHours(13),
            'price' => 50000,
        ]);
        $bookingAtTen = Booking::create([
            'client_id' => $clientB->id,
            'service_id' => $this->service->id,
            'provider_id' => $provider->id,
            'location_id' => $this->location->id,
            'status_id' => $status->id,
            'start_time' => Carbon::tomorrow()->addHours(10),
            'end_time' => Carbon::tomorrow()->addHours(11),
            'price' => 50000,
        ]);

        $response = $this->patchJson("/api/v1/providers/{$provider->id}", [
            'active' => false,
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('message', 'El profesional tiene 2 reservas futuras por atender. Reubica o cancela sus reservas antes de desactivarlo.');
        $response->assertJsonPath('affects.bookings.0.id', $bookingAtTen->id);
        $response->assertJsonPath('affects.bookings.0.client_name', 'Luis Soto');
        $response->assertJsonPath('affects.bookings.1.id', $bookingAtNoon->id);
        $response->assertJsonPath('affects.bookings.1.client_name', 'Ana Pérez');
        $this->assertCount(2, $response->json('affects.bookings'));

        $this->assertDatabaseHas('providers', ['id' => $provider->id, 'active' => 1]);
    }

    // ── S-P5: force key is ignored ─────────────────────────────────

    public function test_update_force_key_does_not_bypass_conflict(): void
    {
        $this->authenticate();

        $provider = $this->makeProvider();
        $this->createFutureBooking($provider, $this->client, $this->makeLiveStatus());

        $response = $this->patchJson("/api/v1/providers/{$provider->id}", [
            'active' => false,
            'force' => true,
        ]);

        $response->assertStatus(409);
        $response->assertJson([
            'error' => 'deactivation_conflict',
            'requires_confirmation' => true,
        ]);
        $this->assertDatabaseHas('providers', ['id' => $provider->id, 'active' => 1]);
    }

    // ── S-P3: no-conflict deactivation returns 200 ─────────────────

    public function test_update_deactivates_provider_without_conflicts(): void
    {
        $this->authenticate();

        $provider = $this->makeProvider();

        $response = $this->patchJson("/api/v1/providers/{$provider->id}", [
            'active' => false,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.active', false);
        $response->assertJson(['message' => 'Profesional desactivado.']);
        $this->assertDatabaseHas('providers', ['id' => $provider->id, 'active' => 0]);
    }

    public function test_update_soft_deleted_future_booking_does_not_block_deactivation(): void
    {
        $this->authenticate();

        $provider = $this->makeProvider();
        $booking = $this->createFutureBooking($provider, $this->client, $this->makeLiveStatus());
        $booking->delete();

        $response = $this->patchJson("/api/v1/providers/{$provider->id}", [
            'active' => false,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.active', false);
        $response->assertJson(['message' => 'Profesional desactivado.']);
        $this->assertDatabaseHas('providers', ['id' => $provider->id, 'active' => 0]);
    }

    // ── S-P4: reactivation always allowed; no-op toggles generic ──

    public function test_update_reactivates_inactive_provider_despite_conflicts(): void
    {
        $this->authenticate();

        $provider = $this->makeProvider(['active' => false]);
        $this->createFutureBooking($provider, $this->client, $this->makeLiveStatus());

        $response = $this->patchJson("/api/v1/providers/{$provider->id}", [
            'active' => true,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.active', true);
        $response->assertJson(['message' => 'Profesional activado.']);
        $this->assertDatabaseHas('providers', ['id' => $provider->id, 'active' => 1]);
    }

    public function test_update_noop_active_true_on_active_provider_returns_generic_message(): void
    {
        $this->authenticate();

        $provider = $this->makeProvider();
        $this->createFutureBooking($provider, $this->client, $this->makeLiveStatus());

        $response = $this->patchJson("/api/v1/providers/{$provider->id}", [
            'active' => true,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.active', true);
        $response->assertJson(['message' => 'Profesional actualizado exitosamente']);
        $this->assertDatabaseHas('providers', ['id' => $provider->id, 'active' => 1]);
    }

    public function test_update_noop_active_false_on_inactive_provider_returns_generic_message(): void
    {
        $this->authenticate();

        $provider = $this->makeProvider(['active' => false]);
        $this->createFutureBooking($provider, $this->client, $this->makeLiveStatus());

        $response = $this->patchJson("/api/v1/providers/{$provider->id}", [
            'active' => false,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.active', false);
        $response->assertJson(['message' => 'Profesional actualizado exitosamente']);
        $this->assertDatabaseHas('providers', ['id' => $provider->id, 'active' => 0]);
    }

    // ── S-P6: plain field updates unaffected by conflicts ──────────

    public function test_update_plain_field_change_is_unaffected_by_conflicts(): void
    {
        $this->authenticate();

        $provider = $this->makeProvider();
        $this->createFutureBooking($provider, $this->client, $this->makeLiveStatus());

        $response = $this->patchJson("/api/v1/providers/{$provider->id}", [
            'phone' => '+56912345678',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Profesional actualizado exitosamente']);
        $response->assertJsonPath('data.phone', '+56912345678');
        $this->assertDatabaseHas('providers', ['id' => $provider->id, 'phone' => '+56912345678', 'active' => 1]);
    }
}
