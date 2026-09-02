<?php

namespace Tests\Unit\Services;

use App\Models\Booking;
use App\Models\BookingStatus;
use App\Models\Client;
use App\Models\Location;
use App\Models\Provider;
use App\Models\Service;
use App\Services\ProviderService;
use Carbon\Carbon;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProviderServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private ProviderService $service;

    private Client $client;

    private Service $serviceModel;

    private Location $location;

    private Provider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ProviderService::class);

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

        $this->location = Location::create([
            'name' => 'Test Location',
            'address' => '123 Test St',
            'active' => true,
        ]);

        $this->provider = Provider::create([
            'first_name' => 'Test',
            'last_name' => 'Provider',
            'email' => 'provider@example.com',
            'location_id' => $this->location->id,
            'active' => true,
        ]);
    }

    private function makeStatus(string $name, bool $isCancellation, bool $isFinalized): BookingStatus
    {
        return BookingStatus::create([
            'name' => $name,
            'is_cancellation' => $isCancellation,
            'is_finalized' => $isFinalized,
        ]);
    }

    private function createBooking(Provider $provider, Client $client, BookingStatus $status, Carbon $start): Booking
    {
        return Booking::create([
            'client_id' => $client->id,
            'service_id' => $this->serviceModel->id,
            'location_id' => $this->location->id,
            'provider_id' => $provider->id,
            'status_id' => $status->id,
            'start_time' => $start,
            'end_time' => $start->copy()->addHour(),
            'price' => 50000,
        ]);
    }

    // ── Seeded booking statuses carry the canonical live/final map ──

    public function test_seeded_booking_statuses_match_canonical_live_final_map(): void
    {
        $this->seed(TestDataSeeder::class);

        $liveIds = DB::table('booking_statuses')
            ->where('is_cancellation', false)
            ->where('is_finalized', false)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertSame([1, 2, 5, 6], $liveIds);

        $finalIds = DB::table('booking_statuses')
            ->where('is_finalized', true)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertSame([3, 4, 7], $finalIds);
    }

    // ── checkDeactivationPreflight ─────────────────────────────────

    public function test_preflight_returns_has_conflicts_false_when_no_future_bookings(): void
    {
        $result = $this->service->checkDeactivationPreflight($this->provider->id);

        $this->assertFalse($result['has_conflicts']);
        $this->assertSame([], $result['bookings']);
    }

    public function test_preflight_returns_conflicts_ordered_by_start_time_when_bookings_exist(): void
    {
        $liveStatus = $this->makeStatus('Confirmed', false, false);

        $clientDiego = Client::create([
            'first_name' => ' Diego',
            'last_name' => 'Morales ',
            'email' => 'diego@example.com',
            'active' => true,
        ]);
        $clientCamila = Client::create([
            'first_name' => 'Camila',
            'last_name' => 'Torres',
            'email' => 'camila@example.com',
            'active' => true,
        ]);

        $lateBooking = $this->createBooking($this->provider, $clientDiego, $liveStatus, Carbon::tomorrow()->addHours(12));
        $earlyBooking = $this->createBooking($this->provider, $clientCamila, $liveStatus, Carbon::tomorrow()->addHours(10));

        // A booking for another provider must not leak into this preflight.
        $otherProvider = Provider::create([
            'first_name' => 'Other',
            'last_name' => 'Provider',
            'email' => 'other@example.com',
            'location_id' => $this->location->id,
            'active' => true,
        ]);
        $this->createBooking($otherProvider, $this->client, $liveStatus, Carbon::tomorrow()->addHours(9));

        $result = $this->service->checkDeactivationPreflight($this->provider->id);

        $expected = [
            [
                'id' => $earlyBooking->id,
                'date' => $earlyBooking->start_time->toDateString(),
                'time' => $earlyBooking->start_time->format('H:i'),
                'client_name' => 'Camila Torres',
                'status' => 'Confirmed',
            ],
            [
                'id' => $lateBooking->id,
                'date' => $lateBooking->start_time->toDateString(),
                'time' => $lateBooking->start_time->format('H:i'),
                'client_name' => 'Diego Morales',
                'status' => 'Confirmed',
            ],
        ];

        $this->assertTrue($result['has_conflicts']);
        $this->assertSame($expected, $result['bookings']);
    }

    public function test_preflight_ignores_cancelled_bookings(): void
    {
        $cancelledStatus = $this->makeStatus('Cancelled', true, false);

        $this->createBooking($this->provider, $this->client, $cancelledStatus, Carbon::tomorrow()->addHours(10));

        $result = $this->service->checkDeactivationPreflight($this->provider->id);

        $this->assertFalse($result['has_conflicts']);
        $this->assertSame([], $result['bookings']);
    }

    public function test_preflight_ignores_finalized_bookings(): void
    {
        $finalizedStatus = $this->makeStatus('Completed', false, true);

        $this->createBooking($this->provider, $this->client, $finalizedStatus, Carbon::tomorrow()->addHours(10));

        $result = $this->service->checkDeactivationPreflight($this->provider->id);

        $this->assertFalse($result['has_conflicts']);
        $this->assertSame([], $result['bookings']);
    }

    public function test_preflight_ignores_past_bookings(): void
    {
        $liveStatus = $this->makeStatus('Confirmed', false, false);

        $this->createBooking($this->provider, $this->client, $liveStatus, Carbon::yesterday()->addHours(10));

        $result = $this->service->checkDeactivationPreflight($this->provider->id);

        $this->assertFalse($result['has_conflicts']);
        $this->assertSame([], $result['bookings']);
    }

    public function test_preflight_ignores_soft_deleted_bookings(): void
    {
        $liveStatus = $this->makeStatus('Confirmed', false, false);

        $booking = $this->createBooking($this->provider, $this->client, $liveStatus, Carbon::tomorrow()->addHours(10));
        $booking->delete();

        $result = $this->service->checkDeactivationPreflight($this->provider->id);

        $this->assertTrue($booking->trashed());
        $this->assertFalse($result['has_conflicts']);
        $this->assertSame([], $result['bookings']);
    }

    public function test_preflight_uses_em_dash_for_client_without_name(): void
    {
        $liveStatus = $this->makeStatus('Confirmed', false, false);

        $unnamedClient = Client::create([
            'first_name' => '',
            'last_name' => '',
            'email' => 'unnamed@example.com',
            'active' => true,
        ]);

        $booking = $this->createBooking($this->provider, $unnamedClient, $liveStatus, Carbon::tomorrow()->addHours(10));

        $result = $this->service->checkDeactivationPreflight($this->provider->id);

        $this->assertTrue($result['has_conflicts']);
        $this->assertSame('—', $result['bookings'][0]['client_name']);
        $this->assertSame($booking->id, $result['bookings'][0]['id']);
    }
}
