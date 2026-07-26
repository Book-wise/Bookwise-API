<?php

namespace Tests\Unit\Services;

use App\Models\Booking;
use App\Models\BookingStatus;
use App\Models\Client;
use App\Models\Location;
use App\Models\Region;
use App\Models\Service;
use App\Services\LocationService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class LocationServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private LocationService $service;

    private Client $client;

    private Service $serviceModel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(LocationService::class);

        $this->client = Client::create([
            'first_name' => 'Test',
            'last_name' => 'Client',
            'email' => 'test@example.com',
            'active' => true,
        ]);

        $this->serviceModel = Service::create([
            'name' => 'Test Service',
            'price' => 50000,
            'duration_minutes' => 60,
        ]);
    }

    // ── resolveTimezone ──────────────────────────────────────────

    public function test_resolve_timezone_returns_america_santiago_for_metropolitana(): void
    {
        $region = Region::create([
            'name' => 'Metropolitana',
            'timezone' => 'America/Santiago',
            'sort_order' => 7,
        ]);

        $timezone = $this->service->resolveTimezone($region->id);

        $this->assertSame('America/Santiago', $timezone);
    }

    public function test_resolve_timezone_returns_america_punta_arenas_for_magallanes(): void
    {
        $region = Region::create([
            'name' => 'Magallanes',
            'timezone' => 'America/Punta_Arenas',
            'sort_order' => 16,
        ]);

        $timezone = $this->service->resolveTimezone($region->id);

        $this->assertSame('America/Punta_Arenas', $timezone);
    }

    public function test_resolve_timezone_throws_model_not_found_for_invalid_region(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->service->resolveTimezone(99999);
    }

    // ── checkDeactivationPreflight ─────────────────────────────────

    public function test_preflight_returns_has_conflicts_false_when_no_future_bookings(): void
    {
        $location = Location::create([
            'name' => 'Test Location',
            'address' => '123 Test St',
            'active' => true,
        ]);

        $result = $this->service->checkDeactivationPreflight($location->id);

        $this->assertFalse($result['has_conflicts']);
        $this->assertEmpty($result['bookings']);
    }

    public function test_preflight_returns_booking_list_when_conflicts_exist(): void
    {
        $location = Location::create([
            'name' => 'Test Location',
            'address' => '123 Test St',
            'active' => true,
        ]);

        $status = BookingStatus::create([
            'name' => 'Confirmed',
            'is_cancellation' => false,
            'is_finalized' => false,
        ]);

        $booking = Booking::create([
            'client_id' => $this->client->id,
            'service_id' => $this->serviceModel->id,
            'location_id' => $location->id,
            'status_id' => $status->id,
            'start_time' => Carbon::tomorrow()->addHours(10),
            'end_time' => Carbon::tomorrow()->addHours(11),
            'price' => 50000,
        ]);

        $result = $this->service->checkDeactivationPreflight($location->id);

        $this->assertTrue($result['has_conflicts']);
        $this->assertCount(1, $result['bookings']);
        $this->assertSame($booking->id, $result['bookings'][0]['id']);
    }

    public function test_preflight_ignores_cancelled_bookings(): void
    {
        $location = Location::create([
            'name' => 'Test Location',
            'address' => '123 Test St',
            'active' => true,
        ]);

        $cancelledStatus = BookingStatus::create([
            'name' => 'Cancelled',
            'is_cancellation' => true,
            'is_finalized' => false,
        ]);

        Booking::create([
            'client_id' => $this->client->id,
            'service_id' => $this->serviceModel->id,
            'location_id' => $location->id,
            'status_id' => $cancelledStatus->id,
            'start_time' => Carbon::tomorrow()->addHours(10),
            'end_time' => Carbon::tomorrow()->addHours(11),
            'price' => 50000,
        ]);

        $result = $this->service->checkDeactivationPreflight($location->id);

        $this->assertFalse($result['has_conflicts']);
        $this->assertEmpty($result['bookings']);
    }

    public function test_preflight_ignores_finalized_bookings(): void
    {
        $location = Location::create([
            'name' => 'Test Location',
            'address' => '123 Test St',
            'active' => true,
        ]);

        $finalizedStatus = BookingStatus::create([
            'name' => 'Completed',
            'is_cancellation' => false,
            'is_finalized' => true,
        ]);

        Booking::create([
            'client_id' => $this->client->id,
            'service_id' => $this->serviceModel->id,
            'location_id' => $location->id,
            'status_id' => $finalizedStatus->id,
            'start_time' => Carbon::tomorrow()->addHours(10),
            'end_time' => Carbon::tomorrow()->addHours(11),
            'price' => 50000,
        ]);

        $result = $this->service->checkDeactivationPreflight($location->id);

        $this->assertFalse($result['has_conflicts']);
        $this->assertEmpty($result['bookings']);
    }

    public function test_preflight_ignores_past_bookings(): void
    {
        $location = Location::create([
            'name' => 'Test Location',
            'address' => '123 Test St',
            'active' => true,
        ]);

        $status = BookingStatus::create([
            'name' => 'Confirmed',
            'is_cancellation' => false,
            'is_finalized' => false,
        ]);

        Booking::create([
            'client_id' => $this->client->id,
            'service_id' => $this->serviceModel->id,
            'location_id' => $location->id,
            'status_id' => $status->id,
            'start_time' => Carbon::yesterday()->addHours(10),
            'end_time' => Carbon::yesterday()->addHours(11),
            'price' => 50000,
        ]);

        $result = $this->service->checkDeactivationPreflight($location->id);

        $this->assertFalse($result['has_conflicts']);
        $this->assertEmpty($result['bookings']);
    }
}
