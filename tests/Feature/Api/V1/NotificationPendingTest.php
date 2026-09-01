<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\BookingStatus;
use App\Models\Client;
use App\Models\Location;
use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class NotificationPendingTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private Client $client;

    private Location $location;

    private Service $service;

    private BookingStatus $confirmedStatus;

    private BookingStatus $cancelStatus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->location = Location::create([
            'name' => 'Test Location',
            'address' => '123 Test St',
        ]);

        $this->service = Service::create([
            'name' => 'Test Service',
            'price' => 50000,
            'duration_minutes' => 60,
        ]);

        $this->confirmedStatus = BookingStatus::create([
            'name' => 'Confirmed',
            'is_cancellation' => false,
        ]);

        $this->cancelStatus = BookingStatus::create([
            'name' => 'Cancelled',
            'is_cancellation' => true,
        ]);

        $this->client = Client::create([
            'first_name' => 'Juan',
            'last_name' => 'Perez',
            'email' => 'juan@test.com',
            'phone' => '+56912345678',
            'active' => true,
        ]);

        $this->admin = User::factory()->create([
            'role' => UserRole::ADMIN,
        ]);
    }

    private function authenticate(array $abilities = ['*']): void
    {
        $token = $this->admin->createToken('test-token', $abilities);
        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken);
    }

    private function createBooking(Carbon $startTime, array $overrides = []): Booking
    {
        return Booking::create(array_merge([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'location_id' => $this->location->id,
            'status_id' => $this->confirmedStatus->id,
            'start_time' => $startTime,
            'end_time' => $startTime->copy()->addHour(),
            'price' => 50000,
        ], $overrides));
    }

    private function pendingUrl(): string
    {
        return '/api/v1/notifications/pending?channel=whatsapp&type=reminder';
    }

    // ── SC8/BR9: 24h window ────────────────────────────────────────

    public function test_pending_returns_24h_booking_within_window(): void
    {
        $this->authenticate();

        $booking = $this->createBooking(Carbon::now()->addHours(24)->subMinutes(10)); // now+23h50m

        $response = $this->getJson($this->pendingUrl());

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.booking_id', $booking->id)
            ->assertJsonPath('data.0.reminder_type', '24h')
            ->assertJsonPath('data.0.client.id', $this->client->id)
            ->assertJsonPath('data.0.client.first_name', 'Juan')
            ->assertJsonPath('data.0.client.last_name', 'Perez')
            ->assertJsonPath('data.0.client.phone', '+56912345678');

        $response->assertJsonPath('data.0.start_time', $booking->fresh()->start_time->toIso8601String());

        // BR11: pending NEVER marks sent.
        $booking->refresh();
        $this->assertNull($booking->reminder_24h_sent_at);
    }

    // ── SC8/BR9: 30m window ────────────────────────────────────────

    public function test_pending_returns_30m_booking_within_window(): void
    {
        $this->authenticate();

        $booking = $this->createBooking(Carbon::now()->addMinutes(30)->subMinutes(3)); // now+27m

        $response = $this->getJson($this->pendingUrl());

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.booking_id', $booking->id)
            ->assertJsonPath('data.0.reminder_type', '30m');
    }

    // ── Ordering: start_time asc ───────────────────────────────────

    public function test_pending_orders_bookings_by_start_time_asc(): void
    {
        $this->authenticate();

        $later = $this->createBooking(Carbon::now()->addHours(24)->subMinutes(5));  // now+23h55m
        $earlier = $this->createBooking(Carbon::now()->addHours(24)->subMinutes(14)); // now+23h46m

        $response = $this->getJson($this->pendingUrl());

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.booking_id', $earlier->id)
            ->assertJsonPath('data.1.booking_id', $later->id);
    }

    // ── SC9: exclusions ────────────────────────────────────────────

    public function test_pending_excludes_booking_with_24h_sent_at_set(): void
    {
        $this->authenticate();

        $this->createBooking(
            Carbon::now()->addHours(24)->subMinutes(10),
            ['reminder_24h_sent_at' => Carbon::now()]
        );

        $response = $this->getJson($this->pendingUrl());

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_pending_excludes_booking_with_30m_sent_at_set(): void
    {
        $this->authenticate();

        $this->createBooking(
            Carbon::now()->addMinutes(30)->subMinutes(3),
            ['reminder_30m_sent_at' => Carbon::now()]
        );

        $response = $this->getJson($this->pendingUrl());

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_pending_excludes_cancelled_booking(): void
    {
        $this->authenticate();

        $this->createBooking(
            Carbon::now()->addHours(24)->subMinutes(10),
            ['status_id' => $this->cancelStatus->id]
        );

        $response = $this->getJson($this->pendingUrl());

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_pending_excludes_booking_with_whatsapp_reminder_off(): void
    {
        $this->authenticate();

        $this->client->update(['whatsapp_reminder' => false]);
        $this->createBooking(Carbon::now()->addHours(24)->subMinutes(10));

        $response = $this->getJson($this->pendingUrl());

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_pending_excludes_booking_with_notifications_disabled(): void
    {
        $this->authenticate();

        $this->client->update(['notifications_enabled' => false]);
        $this->createBooking(Carbon::now()->addHours(24)->subMinutes(10));

        $response = $this->getJson($this->pendingUrl());

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    // ── BR9: window boundaries (whereBetween inclusive) ────────────

    public function test_pending_includes_booking_exactly_at_24h_boundary(): void
    {
        $this->authenticate();

        $booking = $this->createBooking(Carbon::now()->addHours(24)); // now+24h == window end

        $response = $this->getJson($this->pendingUrl());

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.booking_id', $booking->id);
    }

    public function test_pending_excludes_booking_below_24h_window_start(): void
    {
        $this->authenticate();

        $this->createBooking(Carbon::now()->addHours(24)->subMinutes(16)); // now+23h44m

        $response = $this->getJson($this->pendingUrl());

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    // ── BR10: required params ──────────────────────────────────────

    public function test_pending_requires_channel_param(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/v1/notifications/pending?type=reminder');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['channel']);
    }

    public function test_pending_requires_type_param(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/v1/notifications/pending?channel=whatsapp');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    public function test_pending_rejects_unknown_channel(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/v1/notifications/pending?channel=email&type=reminder');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['channel']);
    }

    public function test_pending_rejects_unknown_type(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/v1/notifications/pending?channel=whatsapp&type=email');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    // ── BR18: scopes / auth ────────────────────────────────────────

    public function test_pending_requires_notifications_read_scope(): void
    {
        $this->authenticate(['bookings:read']);

        $response = $this->getJson($this->pendingUrl());

        $response->assertStatus(403)
            ->assertJson([
                'error' => 'forbidden',
            ]);
    }

    public function test_pending_requires_authentication(): void
    {
        $response = $this->getJson($this->pendingUrl());

        $response->assertStatus(401);
    }
}
