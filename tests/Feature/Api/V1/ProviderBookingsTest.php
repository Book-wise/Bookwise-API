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

class ProviderBookingsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private Client $client;

    private Service $service;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => UserRole::ADMIN]);

        $this->client = Client::create([
            'first_name' => 'Juan',
            'last_name' => 'Pérez',
            'email' => 'juan.provider@test.com',
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
        $token = $this->admin->createToken('test-token', ['providers:read']);
        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken);
    }

    private function makeProvider(): Provider
    {
        return Provider::create([
            'first_name' => 'María',
            'last_name' => 'González',
            'email' => 'maria.gonzalez@test.com',
            'location_id' => $this->location->id,
            'active' => true,
        ]);
    }

    private function makeStatus(string $name, bool $isFinalized = false, bool $isCancellation = false): BookingStatus
    {
        return BookingStatus::create([
            'name' => $name,
            'is_finalized' => $isFinalized,
            'is_cancellation' => $isCancellation,
        ]);
    }

    private function makeBooking(Provider $provider, BookingStatus $status, Carbon $start): Booking
    {
        return Booking::create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'provider_id' => $provider->id,
            'location_id' => $this->location->id,
            'status_id' => $status->id,
            'start_time' => $start,
            'end_time' => $start->copy()->addMinutes(60),
            'price' => 50000,
        ]);
    }

    // ── Auth y 404 ──────────────────────────────────────────────────

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/providers/1/bookings')->assertStatus(401);
    }

    public function test_returns_404_when_provider_missing(): void
    {
        $this->authenticate();

        $this->getJson('/api/v1/providers/999/bookings')
            ->assertStatus(404)
            ->assertJson(['error' => 'provider_not_found']);
    }

    // ── Lista por defecto: futuras vivas ────────────────────────────

    public function test_returns_future_live_bookings_with_item_shape(): void
    {
        $this->authenticate();
        $provider = $this->makeProvider();
        $live = $this->makeStatus('Confirmado');
        $final = $this->makeStatus('Asiste', true);

        $future = $this->makeBooking($provider, $live, Carbon::tomorrow()->addHours(10));
        $this->makeBooking($provider, $final, Carbon::tomorrow()->addHours(12)); // no bloquea

        $this->getJson('/api/v1/providers/'.$provider->id.'/bookings')
            ->assertOk()
            ->assertJsonCount(1, 'bookings')
            ->assertJsonPath('bookings.0.id', $future->id)
            ->assertJsonPath('bookings.0.client_name', 'Juan Pérez')
            ->assertJsonPath('bookings.0.status', 'Confirmado')
            ->assertJsonStructure(['bookings' => [['id', 'date', 'time', 'client_name', 'status']]]);
    }

    public function test_past_and_cancelled_bookings_are_excluded_by_default(): void
    {
        $this->authenticate();
        $provider = $this->makeProvider();
        $live = $this->makeStatus('Confirmado');
        $cancelled = $this->makeStatus('Cancelada', false, true);

        $this->makeBooking($provider, $live, Carbon::yesterday());
        $this->makeBooking($provider, $cancelled, Carbon::tomorrow()->addHours(10));

        $this->getJson('/api/v1/providers/'.$provider->id.'/bookings')
            ->assertOk()
            ->assertJsonCount(0, 'bookings');
    }

    // ── Filtros ─────────────────────────────────────────────────────

    public function test_from_filter_limits_to_starting_after(): void
    {
        $this->authenticate();
        $provider = $this->makeProvider();
        $live = $this->makeStatus('Confirmado');

        $tomorrow = $this->makeBooking($provider, $live, Carbon::tomorrow()->addHours(10));
        $nextWeek = $this->makeBooking($provider, $live, Carbon::now()->addWeek());

        $this->getJson('/api/v1/providers/'.$provider->id.'/bookings?from='.$nextWeek->start_time->toDateTimeString())
            ->assertOk()
            ->assertJsonCount(1, 'bookings')
            ->assertJsonPath('bookings.0.id', $nextWeek->id);

        $this->getJson('/api/v1/providers/'.$provider->id.'/bookings?from='.now()->toDateTimeString())
            ->assertOk()
            ->assertJsonCount(2, 'bookings');
    }

    public function test_status_ids_filter_overrides_default_predicate(): void
    {
        $this->authenticate();
        $provider = $this->makeProvider();
        $live = $this->makeStatus('Confirmado');
        $final = $this->makeStatus('Asiste', true);

        $this->makeBooking($provider, $live, Carbon::tomorrow()->addHours(10));
        $finalFuture = $this->makeBooking($provider, $final, Carbon::tomorrow()->addHours(12));

        $this->getJson('/api/v1/providers/'.$provider->id.'/bookings?status_ids[]='.$final->id)
            ->assertOk()
            ->assertJsonCount(1, 'bookings')
            ->assertJsonPath('bookings.0.id', $finalFuture->id);
    }

    public function test_invalid_status_id_returns_422(): void
    {
        $this->authenticate();
        $provider = $this->makeProvider();

        $this->getJson('/api/v1/providers/'.$provider->id.'/bookings?status_ids[]=999')
            ->assertStatus(422);
    }
}
