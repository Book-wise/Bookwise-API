<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Jobs\PushNotificationToCarlitox;
use App\Models\Booking;
use App\Models\BookingStatus;
use App\Models\Client;
use App\Models\Location;
use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TimezoneBookingTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Location $puntaArenas;

    private Location $santiago;

    private Service $service;

    private Client $client;

    private BookingStatus $status;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([PushNotificationToCarlitox::class]);

        $this->puntaArenas = Location::create([
            'name' => 'Kinesilk Punta Arenas',
            'address' => 'Av. Magallanes 500',
            'city' => 'Punta Arenas',
            'timezone' => 'America/Punta_Arenas',
            'active' => true,
            'opening_time' => '09:00:00',
            'closing_time' => '19:00:00',
        ]);

        $this->santiago = Location::create([
            'name' => 'Kinesilk Santiago Centro',
            'address' => 'Av. Providencia 1234',
            'city' => 'Santiago',
            'timezone' => 'America/Santiago',
            'active' => true,
            'opening_time' => '09:00:00',
            'closing_time' => '19:00:00',
        ]);

        $this->service = Service::create([
            'name' => 'Masaje Test',
            'duration_minutes' => 60,
            'price' => 35000,
            'active' => true,
        ]);

        $this->client = Client::create([
            'first_name' => 'Timezone',
            'last_name' => 'Test',
            'email' => 'tz-test@test.com',
            'active' => true,
        ]);

        $this->status = BookingStatus::create([
            'name' => 'Confirmed',
            'is_cancellation' => false,
        ]);

        BookingStatus::create([
            'name' => 'Cancelled',
            'is_cancellation' => true,
        ]);

        $this->admin = User::factory()->create([
            'role' => UserRole::ADMIN,
        ]);

        $token = $this->admin->createToken('test-token', ['*']);
        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken);
    }

    // ── POST booking con start_time LOCAL (sin offset) ────────────

    public function test_punta_arenas_local_time_is_interpreted_as_utc_minus_3(): void
    {
        // Enviar "2027-01-15 09:00" sin offset → debe interpretarse como
        // 09:00 Punta Arenas (UTC-3) → 12:00 UTC → 09:00 Santiago (UTC-3, verano)
        $startLocal = '2027-01-15 09:00';

        $response = $this->postJson('/api/v1/bookings', [
            'start_time' => $startLocal,
            'service_id' => $this->service->id,
            'client_id' => $this->client->id,
            'location_id' => $this->puntaArenas->id,
            'status_id' => $this->status->id,
        ]);

        $response->assertStatus(201);

        $booking = Booking::find($response->json('data.id'));
        $this->assertNotNull($booking);

        // Verano: Punta Arenas y Santiago comparten UTC-3
        // 09:00 Punta Arenas = 12:00 UTC = 09:00 Santiago (misma hora)
        $this->assertSame('2027-01-15 09:00:00', $booking->start_time->format('Y-m-d H:i:s'));
    }

    public function test_santiago_winter_local_time_is_interpreted_as_utc_minus_4(): void
    {
        // Junio: Santiago UTC-4, 09:00 local
        // Ya está en Santiago timezone, se almacena tal cual
        $startLocal = '2027-06-15 09:00';

        $response = $this->postJson('/api/v1/bookings', [
            'start_time' => $startLocal,
            'service_id' => $this->service->id,
            'client_id' => $this->client->id,
            'location_id' => $this->santiago->id,
            'status_id' => $this->status->id,
        ]);

        $response->assertStatus(201);

        $booking = Booking::find($response->json('data.id'));
        $this->assertNotNull($booking);

        $this->assertSame('2027-06-15 09:00:00', $booking->start_time->format('Y-m-d H:i:s'));
    }

    public function test_santiago_summer_local_time_is_interpreted_as_utc_minus_3(): void
    {
        // Enero: Santiago UTC-3, 09:00 local
        $startLocal = '2027-01-15 09:00';

        $response = $this->postJson('/api/v1/bookings', [
            'start_time' => $startLocal,
            'service_id' => $this->service->id,
            'client_id' => $this->client->id,
            'location_id' => $this->santiago->id,
            'status_id' => $this->status->id,
        ]);

        $response->assertStatus(201);

        $booking = Booking::find($response->json('data.id'));
        $this->assertNotNull($booking);

        $this->assertSame('2027-01-15 09:00:00', $booking->start_time->format('Y-m-d H:i:s'));
    }

    // ── POST booking con start_time en UTC (con offset explícito) ─

    public function test_utc_start_time_is_converted_to_santiago_time(): void
    {
        // Cuando el frontend envía UTC (desde slot picker), Carbon respeta el offset
        // 15:00 UTC = 12:00 Santiago (UTC-3, verano)
        $startUtc = '2027-01-15T15:00:00Z';

        $response = $this->postJson('/api/v1/bookings', [
            'start_time' => $startUtc,
            'service_id' => $this->service->id,
            'client_id' => $this->client->id,
            'location_id' => $this->puntaArenas->id,
            'status_id' => $this->status->id,
        ]);

        $response->assertStatus(201);

        $booking = Booking::find($response->json('data.id'));
        $this->assertNotNull($booking);

        // 15:00 UTC = 12:00 Santiago (verano, UTC-3)
        $this->assertSame('2027-01-15 12:00:00', $booking->start_time->format('Y-m-d H:i:s'));
    }

    // ── Diferencia de 1h entre Santiago y Punta Arenas en invierno ─

    public function test_winter_difference_punta_arenas_one_hour_ahead(): void
    {
        // Invierno:
        // 09:00 Punta Arenas (UTC-3) = 12:00 UTC = 08:00 Santiago (UTC-4)
        // 09:00 Santiago (UTC-4) = 13:00 UTC = 09:00 Santiago
        // Usar diferentes clientes para evitar conflicto de overlap
        $client2 = Client::create([
            'first_name' => 'Winter',
            'last_name' => 'Test',
            'email' => 'winter-test@test.com',
            'active' => true,
        ]);

        $startLocal = '2027-06-15 09:00';

        $paResponse = $this->postJson('/api/v1/bookings', [
            'start_time' => $startLocal,
            'service_id' => $this->service->id,
            'client_id' => $this->client->id,
            'location_id' => $this->puntaArenas->id,
            'status_id' => $this->status->id,
        ]);

        $stgResponse = $this->postJson('/api/v1/bookings', [
            'start_time' => $startLocal,
            'service_id' => $this->service->id,
            'client_id' => $client2->id,
            'location_id' => $this->santiago->id,
            'status_id' => $this->status->id,
        ]);

        $paBooking = Booking::find($paResponse->json('data.id'));
        $stgBooking = Booking::find($stgResponse->json('data.id'));

        $this->assertNotNull($paBooking);
        $this->assertNotNull($stgBooking);

        // 09:00 Punta Arenas = 12:00 UTC = 08:00 Santiago
        $this->assertSame('2027-06-15 08:00:00', $paBooking->start_time->format('Y-m-d H:i:s'));
        // 09:00 Santiago = 09:00 Santiago (same timezone)
        $this->assertSame('2027-06-15 09:00:00', $stgBooking->start_time->format('Y-m-d H:i:s'));
    }

    // ── Misma hora en verano ──────────────────────────────────────

    public function test_summer_same_time_in_both_locations(): void
    {
        // Verano: ambos UTC-3 → 09:00 local es la misma hora UTC
        // Usar diferentes clientes para evitar conflicto de overlap
        $client2 = Client::create([
            'first_name' => 'Summer',
            'last_name' => 'Test',
            'email' => 'summer-test@test.com',
            'active' => true,
        ]);

        $startLocal = '2027-01-15 09:00';

        $paResponse = $this->postJson('/api/v1/bookings', [
            'start_time' => $startLocal,
            'service_id' => $this->service->id,
            'client_id' => $this->client->id,
            'location_id' => $this->puntaArenas->id,
            'status_id' => $this->status->id,
        ]);

        $stgResponse = $this->postJson('/api/v1/bookings', [
            'start_time' => $startLocal,
            'service_id' => $this->service->id,
            'client_id' => $client2->id,
            'location_id' => $this->santiago->id,
            'status_id' => $this->status->id,
        ]);

        $paBooking = Booking::find($paResponse->json('data.id'));
        $stgBooking = Booking::find($stgResponse->json('data.id'));

        $this->assertNotNull($paBooking);
        $this->assertNotNull($stgBooking);

        // Ambos son 09:00 porque comparten UTC-3 en verano
        $this->assertSame('2027-01-15 09:00:00', $paBooking->start_time->format('Y-m-d H:i:s'));
        $this->assertSame('2027-01-15 09:00:00', $stgBooking->start_time->format('Y-m-d H:i:s'));
    }

    // ── Booking response muestra start_time en ISO8601 ─────────────

    public function test_booking_response_start_time_is_iso8601_with_correct_time(): void
    {
        $startLocal = '2027-01-15 09:00';

        $response = $this->postJson('/api/v1/bookings', [
            'start_time' => $startLocal,
            'service_id' => $this->service->id,
            'client_id' => $this->client->id,
            'location_id' => $this->puntaArenas->id,
            'status_id' => $this->status->id,
        ]);

        $response->assertStatus(201);

        // start_time debe ser ISO8601 con timezone offset
        $startTime = $response->json('data.start_time');
        $this->assertIsString($startTime);

        // Parsear como ISO8601 y verificar que corresponde al instante correcto
        // 09:00 Punta Arenas (UTC-3) = 12:00 UTC
        $parsed = Carbon::parse($startTime);
        $this->assertSame('2027-01-15 12:00:00', $parsed->setTimezone('UTC')->format('Y-m-d H:i:s'));
    }
}
