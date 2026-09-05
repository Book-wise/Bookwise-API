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

class NotificationAckTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private Client $client;

    private Location $location;

    private Service $service;

    private BookingStatus $confirmedStatus;

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

    private function createBooking(?Carbon $startTime = null): Booking
    {
        $startTime ??= Carbon::tomorrow()->addHours(10);

        return Booking::create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'location_id' => $this->location->id,
            'status_id' => $this->confirmedStatus->id,
            'start_time' => $startTime,
            'end_time' => $startTime->copy()->addHour(),
            'price' => 50000,
        ]);
    }

    // ── SC10: ack marks the correct sent_at ────────────────────────

    public function test_ack_marks_24h_sent_at(): void
    {
        $this->authenticate();

        $booking = $this->createBooking();

        $response = $this->postJson('/api/v1/notifications/reminders/ack', [
            'booking_id' => $booking->id,
            'reminder_type' => '24h',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.booking_id', $booking->id)
            ->assertJsonPath('data.reminder_type', '24h');

        $booking->refresh();
        $this->assertNotNull($booking->reminder_24h_sent_at);
        $this->assertNull($booking->reminder_30m_sent_at);
    }

    public function test_ack_marks_30m_sent_at(): void
    {
        $this->authenticate();

        $booking = $this->createBooking();

        $response = $this->postJson('/api/v1/notifications/reminders/ack', [
            'booking_id' => $booking->id,
            'reminder_type' => '30m',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.reminder_type', '30m');

        $booking->refresh();
        $this->assertNotNull($booking->reminder_30m_sent_at);
        $this->assertNull($booking->reminder_24h_sent_at);
    }

    // ── BR12: repeat ack → 200 no-op, sent_at unchanged ────────────

    public function test_repeat_ack_is_idempotent(): void
    {
        $this->authenticate();

        $booking = $this->createBooking();

        $this->postJson('/api/v1/notifications/reminders/ack', [
            'booking_id' => $booking->id,
            'reminder_type' => '24h',
        ])->assertOk();

        $sentAt = $booking->fresh()->reminder_24h_sent_at;

        $response = $this->postJson('/api/v1/notifications/reminders/ack', [
            'booking_id' => $booking->id,
            'reminder_type' => '24h',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.sent_at', $sentAt->toIso8601String());

        $this->assertSame(
            $sentAt->toIso8601String(),
            $booking->fresh()->reminder_24h_sent_at->toIso8601String()
        );
    }

    // ── BR13: validation ───────────────────────────────────────────

    public function test_ack_rejects_invalid_reminder_type(): void
    {
        $this->authenticate();

        $booking = $this->createBooking();

        $response = $this->postJson('/api/v1/notifications/reminders/ack', [
            'booking_id' => $booking->id,
            'reminder_type' => '10h',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['reminder_type']);
    }

    public function test_ack_requires_booking_id(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/v1/notifications/reminders/ack', [
            'reminder_type' => '24h',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['booking_id']);
    }

    public function test_ack_missing_booking_returns_404(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/v1/notifications/reminders/ack', [
            'booking_id' => 999999,
            'reminder_type' => '24h',
        ]);

        $response->assertStatus(404);
    }

    // ── BR18: scopes / auth ────────────────────────────────────────

    public function test_ack_requires_notifications_write_scope(): void
    {
        $this->authenticate(['bookings:read']);

        $booking = $this->createBooking();

        $response = $this->postJson('/api/v1/notifications/reminders/ack', [
            'booking_id' => $booking->id,
            'reminder_type' => '24h',
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'error' => 'forbidden',
            ]);
    }

    public function test_ack_requires_authentication(): void
    {
        $booking = $this->createBooking();

        $response = $this->postJson('/api/v1/notifications/reminders/ack', [
            'booking_id' => $booking->id,
            'reminder_type' => '24h',
        ]);

        $response->assertStatus(401);
    }

    // ── D2 cross-check: acked booking disappears from pending ──────

    public function test_acked_booking_disappears_from_pending(): void
    {
        $this->authenticate();

        $booking = $this->createBooking(Carbon::now()->addHours(24)->subMinutes(10));

        $this->getJson('/api/v1/notifications/pending?channel=whatsapp&type=reminder')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->postJson('/api/v1/notifications/reminders/ack', [
            'booking_id' => $booking->id,
            'reminder_type' => '24h',
        ])->assertOk();

        $this->getJson('/api/v1/notifications/pending?channel=whatsapp&type=reminder')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
