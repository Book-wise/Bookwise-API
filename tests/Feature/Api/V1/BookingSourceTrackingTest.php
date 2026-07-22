<?php

namespace Tests\Feature\Api\V1;

use App\Enums\BookingSource;
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

class BookingSourceTrackingTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Location $location;

    private Provider $provider;

    private Service $service;

    private Client $client;

    private BookingStatus $status;

    private User $admin;

    private User $agent;

    private User $providerUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->location = Location::create([
            'name' => 'Test Location',
            'address' => '123 Test St',
        ]);

        $this->provider = Provider::create([
            'first_name' => 'Test',
            'last_name' => 'Provider',
            'email' => 'provider@test.com',
            'location_id' => $this->location->id,
            'active' => true,
        ]);

        $this->service = Service::create([
            'name' => 'Test Service',
            'price' => 50000,
            'duration_minutes' => 60,
        ]);

        $this->client = Client::create([
            'first_name' => 'Test',
            'last_name' => 'Client',
            'email' => 'client@test.com',
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

        $this->agent = User::factory()->create([
            'role' => UserRole::AGENT,
        ]);

        $this->providerUser = User::factory()->create([
            'role' => UserRole::PROVIDER,
            'provider_id' => $this->provider->id,
        ]);
    }

    private function authenticateAs(User $user, array $abilities = ['*']): void
    {
        $token = $user->createToken('test-token', $abilities);
        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken);
    }

    private function validBookingData(): array
    {
        $startTime = Carbon::tomorrow()->addHours(10);
        $endTime = Carbon::tomorrow()->addHours(11);

        return [
            'start_time' => $startTime->toIso8601String(),
            'end_time' => $endTime->toIso8601String(),
            'service_id' => $this->service->id,
            'client_id' => $this->client->id,
            'location_id' => $this->location->id,
            'status_id' => $this->status->id,
        ];
    }

    // ── S1: Admin creates booking → created_via = last_modified_via = 'admin_calendar' ────

    public function test_admin_creates_booking_sets_admin_calendar_source(): void
    {
        $this->authenticateAs($this->admin);

        $response = $this->postJson('/api/v1/bookings', $this->validBookingData());

        $response->assertStatus(201);
        $response->assertJsonPath('data.created_via', 'admin_calendar');
        $response->assertJsonPath('data.last_modified_via', 'admin_calendar');

        $this->assertDatabaseHas('bookings', [
            'id' => $response->json('data.id'),
            'created_via' => 'admin_calendar',
            'last_modified_via' => 'admin_calendar',
        ]);
    }

    // ── S2: Agent creates booking → created_via = last_modified_via = 'agent' ──────────

    public function test_agent_creates_booking_sets_agent_source(): void
    {
        $this->authenticateAs($this->agent, ['bookings:write']);

        $response = $this->postJson('/api/v1/bookings', $this->validBookingData());

        $response->assertStatus(201);
        $response->assertJsonPath('data.created_via', 'agent');
        $response->assertJsonPath('data.last_modified_via', 'agent');

        $this->assertDatabaseHas('bookings', [
            'id' => $response->json('data.id'),
            'created_via' => 'agent',
            'last_modified_via' => 'agent',
        ]);
    }

    // ── S5: Admin updates booking → last_modified_via updated, created_via preserved ───

    public function test_admin_updates_booking_sets_last_modified_via_only(): void
    {
        $this->authenticateAs($this->admin);

        // Create a booking with webhook source first
        $booking = Booking::create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'location_id' => $this->location->id,
            'status_id' => $this->status->id,
            'start_time' => Carbon::tomorrow()->addHours(10),
            'end_time' => Carbon::tomorrow()->addHours(11),
            'price' => 50000,
            'created_via' => BookingSource::OnlineWebhook,
            'last_modified_via' => BookingSource::OnlineWebhook,
        ]);

        $response = $this->patchJson("/api/v1/bookings/{$booking->id}", [
            'notes' => 'Updated by admin',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.created_via', 'online_webhook');
        $response->assertJsonPath('data.last_modified_via', 'admin_calendar');

        $booking->refresh();
        $this->assertSame('online_webhook', $booking->created_via?->value);
        $this->assertSame('admin_calendar', $booking->last_modified_via?->value);
    }

    // ── S6: Agent cancels booking → last_modified_via = 'agent', created_via preserved ─

    public function test_agent_cancels_booking_sets_last_modified_via_only(): void
    {
        $this->authenticateAs($this->agent, ['bookings:write']);

        $booking = Booking::create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'location_id' => $this->location->id,
            'status_id' => $this->status->id,
            'start_time' => Carbon::tomorrow()->addHours(10),
            'end_time' => Carbon::tomorrow()->addHours(11),
            'price' => 50000,
            'created_via' => BookingSource::AdminCalendar,
            'last_modified_via' => BookingSource::AdminCalendar,
        ]);

        $cancelStatus = BookingStatus::where('is_cancellation', true)->first();

        $response = $this->patchJson("/api/v1/bookings/{$booking->id}/cancel");

        $response->assertStatus(200);
        $response->assertJsonPath('data.created_via', 'admin_calendar');
        $response->assertJsonPath('data.last_modified_via', 'agent');

        $booking->refresh();
        $this->assertSame('admin_calendar', $booking->created_via?->value);
        $this->assertSame('agent', $booking->last_modified_via?->value);
        $this->assertSame($cancelStatus->id, $booking->status_id);
    }

    // ── S8: API response exposes source fields ───────────────────────────────────────

    public function test_booking_response_exposes_source_fields(): void
    {
        $this->authenticateAs($this->admin);

        $booking = Booking::create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'location_id' => $this->location->id,
            'status_id' => $this->status->id,
            'start_time' => Carbon::tomorrow()->addHours(10),
            'end_time' => Carbon::tomorrow()->addHours(11),
            'price' => 50000,
            'created_via' => BookingSource::AdminCalendar,
            'last_modified_via' => BookingSource::AdminCalendar,
        ]);

        $response = $this->getJson("/api/v1/bookings/{$booking->id}");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'created_via',
                'last_modified_via',
            ],
        ]);
        $this->assertIsString($response->json('data.created_via'));
        $this->assertIsString($response->json('data.last_modified_via'));
    }

    // ── S8b: Response excludes last_modified_via when null ──────────────────────────

    public function test_booking_response_omits_last_modified_via_when_null(): void
    {
        $this->authenticateAs($this->admin);

        $booking = Booking::create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'location_id' => $this->location->id,
            'status_id' => $this->status->id,
            'start_time' => Carbon::tomorrow()->addHours(10),
            'end_time' => Carbon::tomorrow()->addHours(11),
            'price' => 50000,
            'created_via' => BookingSource::AdminCalendar,
            'last_modified_via' => null,
        ]);

        $response = $this->getJson("/api/v1/bookings/{$booking->id}");

        $response->assertStatus(200);
        $this->assertSame('admin_calendar', $response->json('data.created_via'));
        $this->assertNull($response->json('data.last_modified_via'));
    }

    // ── S9: Agent cancels with bookings:write scope ─────────────────────────────────

    public function test_agent_cancels_with_bookings_write_scope_succeeds(): void
    {
        $this->authenticateAs($this->agent, ['bookings:write']);

        $booking = Booking::create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'location_id' => $this->location->id,
            'status_id' => $this->status->id,
            'start_time' => Carbon::tomorrow()->addHours(10),
            'end_time' => Carbon::tomorrow()->addHours(11),
            'price' => 50000,
            'created_via' => BookingSource::AdminCalendar,
            'last_modified_via' => BookingSource::AdminCalendar,
        ]);

        $response = $this->patchJson("/api/v1/bookings/{$booking->id}/cancel");

        $response->assertStatus(200);
        $this->assertSame('agent', $response->json('data.last_modified_via'));
    }

    public function test_agent_without_bookings_write_scope_cannot_cancel(): void
    {
        $this->authenticateAs($this->agent, ['bookings:read']);

        $booking = Booking::create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'location_id' => $this->location->id,
            'status_id' => $this->status->id,
            'start_time' => Carbon::tomorrow()->addHours(10),
            'end_time' => Carbon::tomorrow()->addHours(11),
            'price' => 50000,
            'created_via' => BookingSource::AdminCalendar,
            'last_modified_via' => BookingSource::AdminCalendar,
        ]);

        $response = $this->patchJson("/api/v1/bookings/{$booking->id}/cancel");

        $response->assertStatus(403);
    }

    // ── S10: Old records have null source ───────────────────────────────────────────

    public function test_old_records_have_null_source(): void
    {
        $this->authenticateAs($this->admin);

        // Create a booking without source fields (simulating pre-migration record)
        $booking = Booking::create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'location_id' => $this->location->id,
            'status_id' => $this->status->id,
            'start_time' => Carbon::tomorrow()->addHours(10),
            'end_time' => Carbon::tomorrow()->addHours(11),
            'price' => 50000,
        ]);

        $this->assertNull($booking->created_via);
        $this->assertNull($booking->last_modified_via);

        $response = $this->getJson("/api/v1/bookings/{$booking->id}");

        $response->assertStatus(200);
        $this->assertNull($response->json('data.created_via'));
    }

    // ── S11: Seeded data has created_via ────────────────────────────────────────────

    public function test_created_via_is_not_null_on_new_bookings(): void
    {
        $this->authenticateAs($this->admin);

        $response = $this->postJson('/api/v1/bookings', $this->validBookingData());

        $response->assertStatus(201);
        $this->assertNotNull($response->json('data.created_via'));
    }

    // ── Provider role maps to admin_calendar ────────────────────────────────────────

    public function test_provider_creates_booking_sets_admin_calendar_source(): void
    {
        $this->authenticateAs($this->providerUser);

        $response = $this->postJson('/api/v1/bookings', $this->validBookingData());

        $response->assertStatus(201);
        $response->assertJsonPath('data.created_via', 'admin_calendar');
        $response->assertJsonPath('data.last_modified_via', 'admin_calendar');
    }

    // ── Provider updates booking → last_modified_via = admin_calendar ───────────────

    public function test_provider_updates_booking_sets_last_modified_via(): void
    {
        $this->authenticateAs($this->providerUser);

        $booking = Booking::create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'location_id' => $this->location->id,
            'status_id' => $this->status->id,
            'start_time' => Carbon::tomorrow()->addHours(10),
            'end_time' => Carbon::tomorrow()->addHours(11),
            'price' => 50000,
            'created_via' => BookingSource::OnlineWebhook,
            'last_modified_via' => BookingSource::OnlineWebhook,
        ]);

        $response = $this->patchJson("/api/v1/bookings/{$booking->id}", [
            'notes' => 'Updated by provider',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.last_modified_via', 'admin_calendar');
        $response->assertJsonPath('data.created_via', 'online_webhook');
    }

    // ── Booking enum casting ────────────────────────────────────────────────────────

    public function test_booking_source_enum_cast(): void
    {
        $booking = Booking::create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'location_id' => $this->location->id,
            'status_id' => $this->status->id,
            'start_time' => Carbon::tomorrow()->addHours(10),
            'end_time' => Carbon::tomorrow()->addHours(11),
            'price' => 50000,
            'created_via' => BookingSource::AdminCalendar,
            'last_modified_via' => BookingSource::OnlineWebhook,
        ]);

        $this->assertInstanceOf(BookingSource::class, $booking->created_via);
        $this->assertInstanceOf(BookingSource::class, $booking->last_modified_via);
        $this->assertTrue($booking->created_via === BookingSource::AdminCalendar);
        $this->assertTrue($booking->last_modified_via === BookingSource::OnlineWebhook);
        $this->assertSame('admin_calendar', $booking->created_via->value);
        $this->assertSame('online_webhook', $booking->last_modified_via->value);
    }

    // ── UserRole AGENT has bookings:write ────────────────────────────────────────────

    public function test_agent_token_abilities_includes_bookings_write(): void
    {
        $abilities = UserRole::AGENT->tokenAbilities();

        $this->assertContains('bookings:write', $abilities);
    }

    // ── Created_via is write-once immutable ─────────────────────────────────────────

    public function test_created_via_is_immutable_after_creation(): void
    {
        $this->authenticateAs($this->admin);

        $booking = Booking::create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'location_id' => $this->location->id,
            'status_id' => $this->status->id,
            'start_time' => Carbon::tomorrow()->addHours(10),
            'end_time' => Carbon::tomorrow()->addHours(11),
            'price' => 50000,
            'created_via' => BookingSource::AdminCalendar,
            'last_modified_via' => BookingSource::AdminCalendar,
        ]);

        // Update — should NOT change created_via
        $this->patchJson("/api/v1/bookings/{$booking->id}", [
            'notes' => 'Test immutability',
        ]);

        $booking->refresh();
        $this->assertSame('admin_calendar', $booking->created_via?->value);

        // Cancel — should NOT change created_via
        $this->patchJson("/api/v1/bookings/{$booking->id}/cancel");

        $booking->refresh();
        $this->assertSame('admin_calendar', $booking->created_via?->value);
    }

    // ── Webhook: S3 creation sets online_webhook ─────────────────────────────────────
    // (uses the existing WebhookOrderCompletedTest infrastructure pattern)

    public function test_webhook_created_booking_has_online_webhook_source(): void
    {
        $secret = 'test-webhook-secret';
        config(['services.woocommerce.webhook_secret' => $secret]);

        $payload = [
            'id' => 99901,
            'status' => 'completed',
            'total' => '50000',
            'date_paid' => Carbon::now()->toIso8601String(),
            'payment_method' => 'credit_card',
            'billing' => [
                'email' => 'webhook-source@test.com',
                'first_name' => 'Source',
                'last_name' => 'Test',
                'phone' => '+1234567890',
            ],
            'line_items' => [
                [
                    'id' => 1,
                    'name' => 'Test Service',
                    'meta_data' => [
                        ['key' => '_kinesilk_slot_start', 'value' => Carbon::tomorrow()->addHours(14)->toIso8601String()],
                        ['key' => '_kinesilk_slot_end', 'value' => Carbon::tomorrow()->addHours(15)->toIso8601String()],
                        ['key' => '_kinesilk_location_id', 'value' => (string) $this->location->id],
                        ['key' => '_kinesilk_service_id', 'value' => (string) $this->service->id],
                        ['key' => '_kinesilk_duration_minutes', 'value' => '60'],
                    ],
                ],
            ],
        ];

        $json = json_encode($payload);
        $signature = base64_encode(hash_hmac('sha256', $json, $secret, true));

        $response = $this->withHeaders([
            'X-WC-Webhook-Signature' => $signature,
            'X-WC-Webhook-Topic' => 'order.updated',
        ])->postJson('/api/v1/webhooks/woocommerce', $payload);

        $response->assertStatus(200);

        $booking = Booking::where('wc_order_id', 99901)->first();
        $this->assertNotNull($booking);
        $this->assertSame('online_webhook', $booking->created_via?->value);
        $this->assertSame('online_webhook', $booking->last_modified_via?->value);
    }

    // ── S4: Webhook replay does NOT change source ───────────────────────────────────

    public function test_webhook_replay_does_not_change_source(): void
    {
        $secret = 'test-webhook-secret';
        config(['services.woocommerce.webhook_secret' => $secret]);

        // Pre-create a booking with admin source
        $booking = Booking::create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'location_id' => $this->location->id,
            'status_id' => $this->status->id,
            'start_time' => Carbon::tomorrow()->addHours(10),
            'end_time' => Carbon::tomorrow()->addHours(11),
            'price' => 50000,
            'wc_order_id' => 99902,
            'created_via' => BookingSource::AdminCalendar,
            'last_modified_via' => BookingSource::AdminCalendar,
        ]);

        $payload = [
            'id' => 99902,
            'status' => 'completed',
            'total' => '50000',
            'date_paid' => Carbon::now()->toIso8601String(),
            'payment_method' => 'credit_card',
            'billing' => [
                'email' => 'replay-source@test.com',
                'first_name' => 'Replay',
                'last_name' => 'Test',
                'phone' => '+1234567890',
            ],
            'line_items' => [
                [
                    'id' => 1,
                    'name' => 'Test Service',
                    'meta_data' => [
                        ['key' => '_kinesilk_slot_start', 'value' => Carbon::tomorrow()->addHours(14)->toIso8601String()],
                        ['key' => '_kinesilk_slot_end', 'value' => Carbon::tomorrow()->addHours(15)->toIso8601String()],
                        ['key' => '_kinesilk_location_id', 'value' => (string) $this->location->id],
                        ['key' => '_kinesilk_service_id', 'value' => (string) $this->service->id],
                        ['key' => '_kinesilk_duration_minutes', 'value' => '60'],
                    ],
                ],
            ],
        ];

        $json = json_encode($payload);
        $signature = base64_encode(hash_hmac('sha256', $json, $secret, true));

        $response = $this->withHeaders([
            'X-WC-Webhook-Signature' => $signature,
            'X-WC-Webhook-Topic' => 'order.updated',
        ])->postJson('/api/v1/webhooks/woocommerce', $payload);

        $response->assertStatus(200);

        $booking->refresh();
        $this->assertSame('admin_calendar', $booking->created_via?->value);
        $this->assertSame('admin_calendar', $booking->last_modified_via?->value);
    }

    // ── S7: Webhook refund sets last_modified_via = 'online_webhook' ────────────────

    public function test_webhook_refund_sets_last_modified_via(): void
    {
        $secret = 'test-webhook-secret';
        config(['services.woocommerce.webhook_secret' => $secret]);

        $booking = Booking::create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'location_id' => $this->location->id,
            'status_id' => $this->status->id,
            'start_time' => Carbon::tomorrow()->addHours(10),
            'end_time' => Carbon::tomorrow()->addHours(11),
            'price' => 50000,
            'wc_order_id' => 99903,
            'created_via' => BookingSource::AdminCalendar,
            'last_modified_via' => BookingSource::AdminCalendar,
        ]);

        $payload = [
            'id' => 99903,
            'status' => 'refunded',
            'total' => '50000',
        ];

        $json = json_encode($payload);
        $signature = base64_encode(hash_hmac('sha256', $json, $secret, true));

        $this->withHeaders([
            'X-WC-Webhook-Signature' => $signature,
            'X-WC-Webhook-Topic' => 'order.refunded',
        ])->postJson('/api/v1/webhooks/woocommerce', $payload);

        $booking->refresh();
        $this->assertSame('admin_calendar', $booking->created_via?->value);
        $this->assertSame('online_webhook', $booking->last_modified_via?->value);
        $this->assertTrue($booking->status->is_cancellation);
    }

    // ── Cancel route allows provider role (policy enforces scoping) ──────

    public function test_cancel_route_allows_provider_role(): void
    {
        $this->authenticateAs($this->providerUser);

        $booking = Booking::create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'location_id' => $this->location->id,
            'status_id' => $this->status->id,
            'start_time' => Carbon::tomorrow()->addHours(10),
            'end_time' => Carbon::tomorrow()->addHours(11),
            'price' => 50000,
        ]);

        $response = $this->patchJson("/api/v1/bookings/{$booking->id}/cancel");

        $response->assertStatus(200);
    }
}
