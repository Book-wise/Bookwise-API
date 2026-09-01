<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Jobs\ProcessWooCommerceWebhook;
use App\Jobs\PushNotificationToCarlitox;
use App\Models\Booking;
use App\Models\BookingStatus;
use App\Models\Client;
use App\Models\Location;
use App\Models\Service;
use App\Models\User;
use App\Models\WoocommerceWebhooksLog;
use App\Services\BookingService;
use App\Services\ClientService;
use App\Services\SaleService;
use App\Services\WooCommerceCustomerService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class BookingLifecycleEventsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private Client $client;

    private Location $location;

    private Service $service;

    private BookingStatus $confirmedStatus;

    private BookingStatus $cancelStatus;

    private string $webhookSecret = 'test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.woocommerce.webhook_secret' => $this->webhookSecret]);

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

    private function authenticate(): void
    {
        $token = $this->admin->createToken('test-token', ['*']);
        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken);
    }

    private function validBookingData(array $overrides = []): array
    {
        $startTime = Carbon::tomorrow()->addHours(10);
        $endTime = Carbon::tomorrow()->addHours(11);

        return array_merge([
            'start_time' => $startTime->toIso8601String(),
            'end_time' => $endTime->toIso8601String(),
            'service_id' => $this->service->id,
            'client_id' => $this->client->id,
            'location_id' => $this->location->id,
            'status_id' => $this->confirmedStatus->id,
        ], $overrides);
    }

    private function createBooking(array $overrides = []): Booking
    {
        return Booking::create(array_merge([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'location_id' => $this->location->id,
            'status_id' => $this->confirmedStatus->id,
            'start_time' => Carbon::tomorrow()->addHours(10),
            'end_time' => Carbon::tomorrow()->addHours(11),
            'price' => 50000,
        ], $overrides));
    }

    private function buildPayload(array $overrides = []): array
    {
        $defaults = [
            'id' => 12345,
            'status' => 'completed',
            'total' => '50000',
            'date_paid' => Carbon::now()->toIso8601String(),
            'payment_method' => 'credit_card',
            'billing' => [
                'email' => 'customer@test.com',
                'first_name' => 'John',
                'last_name' => 'Doe',
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

        return array_replace_recursive($defaults, $overrides);
    }

    private function runWebhookJob(string $event, array $payloadData): WoocommerceWebhooksLog
    {
        $log = WoocommerceWebhooksLog::create([
            'event' => $event,
            'wc_order_id' => $payloadData['id'] ?? null,
            'wc_entity_id' => $payloadData['id'] ?? null,
            'entity_type' => 'order',
            'payload' => json_encode($payloadData),
            'status' => 'received',
        ]);

        $job = new ProcessWooCommerceWebhook(
            event: $event,
            payload: json_encode($payloadData),
            logId: $log->id,
        );

        $job->handle(
            app(WooCommerceCustomerService::class),
            app(ClientService::class),
            app(BookingService::class),
            app(SaleService::class),
        );

        return $log->fresh();
    }

    // ── SC4/BR5: Store with confirmed status → created + confirmed ──

    public function test_store_with_confirmed_status_pushes_created_and_confirmed(): void
    {
        Queue::fake();

        $this->authenticate();

        $this->postJson('/api/v1/bookings', $this->validBookingData())->assertStatus(201);

        Queue::assertPushed(PushNotificationToCarlitox::class, function ($job) {
            return $job->event === PushNotificationToCarlitox::EVENT_BOOKING_CREATED
                && $job->channel === PushNotificationToCarlitox::CHANNEL_EMAIL;
        });

        Queue::assertPushed(PushNotificationToCarlitox::class, function ($job) {
            return $job->event === PushNotificationToCarlitox::EVENT_BOOKING_CONFIRMED
                && $job->channel === PushNotificationToCarlitox::CHANNEL_EMAIL;
        });
    }

    // ── BR1/BR2: Master gate + per-flag gating on store ────────────

    public function test_store_with_email_new_booking_off_pushes_only_confirmed(): void
    {
        Queue::fake();

        $this->client->update(['email_new_booking' => false]);
        $this->authenticate();

        $this->postJson('/api/v1/bookings', $this->validBookingData())->assertStatus(201);

        Queue::assertNotPushed(PushNotificationToCarlitox::class, function ($job) {
            return $job->event === PushNotificationToCarlitox::EVENT_BOOKING_CREATED;
        });

        Queue::assertPushed(PushNotificationToCarlitox::class, function ($job) {
            return $job->event === PushNotificationToCarlitox::EVENT_BOOKING_CONFIRMED;
        });
    }

    public function test_store_with_both_email_flags_off_pushes_nothing(): void
    {
        Queue::fake();

        $this->client->update([
            'email_new_booking' => false,
            'email_booking_confirmation' => false,
        ]);
        $this->authenticate();

        $this->postJson('/api/v1/bookings', $this->validBookingData())->assertStatus(201);

        Queue::assertNothingPushed();
    }

    public function test_store_with_notifications_disabled_pushes_nothing(): void
    {
        Queue::fake();

        $this->client->update(['notifications_enabled' => false]);
        $this->authenticate();

        $this->postJson('/api/v1/bookings', $this->validBookingData())->assertStatus(201);

        Queue::assertNothingPushed();
    }

    // ── BR6: Store with cancellation status → only created ─────────

    public function test_store_with_cancellation_status_pushes_only_created(): void
    {
        Queue::fake();

        $this->authenticate();

        $this->postJson('/api/v1/bookings', $this->validBookingData([
            'status_id' => $this->cancelStatus->id,
        ]))->assertStatus(201);

        Queue::assertPushed(PushNotificationToCarlitox::class, function ($job) {
            return $job->event === PushNotificationToCarlitox::EVENT_BOOKING_CREATED;
        });

        Queue::assertNotPushed(PushNotificationToCarlitox::class, function ($job) {
            return $job->event === PushNotificationToCarlitox::EVENT_BOOKING_CONFIRMED;
        });
    }

    // ── BR7: Idempotency replay of store → no dispatch ─────────────

    public function test_store_idempotency_replay_pushes_nothing(): void
    {
        Queue::fake();

        $this->authenticate();
        $data = $this->validBookingData();
        $headers = ['Idempotency-Key' => 'booking-store-key-1'];

        $this->withHeaders($headers)->postJson('/api/v1/bookings', $data)->assertStatus(201);

        Queue::assertPushed(PushNotificationToCarlitox::class, 2);

        // Second identical request → cached response, no events.
        Queue::fake();

        $this->withHeaders($headers)->postJson('/api/v1/bookings', $data)->assertStatus(201);

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('bookings', 1);
    }

    // ── SC5/BR8: Cancel dispatches BookingCancelled ────────────────

    public function test_cancel_pushes_cancelled_jobs_for_enabled_channels(): void
    {
        Queue::fake();

        $booking = $this->createBooking();
        $this->authenticate();

        $this->patchJson("/api/v1/bookings/{$booking->id}/cancel")->assertStatus(200);

        Queue::assertPushed(PushNotificationToCarlitox::class, function ($job) {
            return $job->event === PushNotificationToCarlitox::EVENT_BOOKING_CANCELLED
                && $job->channel === PushNotificationToCarlitox::CHANNEL_EMAIL;
        });

        Queue::assertPushed(PushNotificationToCarlitox::class, function ($job) {
            return $job->event === PushNotificationToCarlitox::EVENT_BOOKING_CANCELLED
                && $job->channel === PushNotificationToCarlitox::CHANNEL_WHATSAPP;
        });
    }

    public function test_cancel_with_flags_off_pushes_nothing(): void
    {
        Queue::fake();

        $this->client->update([
            'email_booking_cancellation' => false,
            'whatsapp_cancellation_confirmation' => false,
        ]);
        $booking = $this->createBooking();
        $this->authenticate();

        $this->patchJson("/api/v1/bookings/{$booking->id}/cancel")->assertStatus(200);

        Queue::assertNothingPushed();
    }

    public function test_cancel_already_cancelled_returns_422_and_pushes_nothing(): void
    {
        Queue::fake();

        $booking = $this->createBooking(['status_id' => $this->cancelStatus->id]);
        $this->authenticate();

        $this->patchJson("/api/v1/bookings/{$booking->id}/cancel")->assertStatus(422);

        Queue::assertNothingPushed();
    }

    // ── SC6/BR7: Webhook order.completed ───────────────────────────

    public function test_webhook_order_completed_pushes_created_and_confirmed(): void
    {
        Queue::fake();

        $this->runWebhookJob('order.completed', $this->buildPayload());

        Queue::assertPushed(PushNotificationToCarlitox::class, function ($job) {
            return $job->event === PushNotificationToCarlitox::EVENT_BOOKING_CREATED
                && $job->channel === PushNotificationToCarlitox::CHANNEL_EMAIL;
        });

        Queue::assertPushed(PushNotificationToCarlitox::class, function ($job) {
            return $job->event === PushNotificationToCarlitox::EVENT_BOOKING_CONFIRMED
                && $job->channel === PushNotificationToCarlitox::CHANNEL_EMAIL;
        });
    }

    public function test_webhook_order_completed_replay_pushes_nothing(): void
    {
        Queue::fake();

        $this->runWebhookJob('order.completed', $this->buildPayload());
        Queue::assertPushed(PushNotificationToCarlitox::class, 2);

        Queue::fake();

        $this->runWebhookJob('order.completed', $this->buildPayload());

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_webhook_order_completed_unavailable_slot_pushes_nothing(): void
    {
        Queue::fake();

        // Pre-create an overlapping active booking so the slot is unavailable.
        $existingClient = Client::create([
            'first_name' => 'Existing',
            'last_name' => 'Client',
            'email' => 'existing@test.com',
            'active' => true,
        ]);

        $payload = $this->buildPayload();
        $slotStart = Carbon::tomorrow()->addHours(14);
        $slotEnd = Carbon::tomorrow()->addHours(15);

        Booking::create([
            'client_id' => $existingClient->id,
            'service_id' => $this->service->id,
            'location_id' => $this->location->id,
            'status_id' => $this->confirmedStatus->id,
            'start_time' => $slotStart,
            'end_time' => $slotEnd,
            'price' => 50000,
        ]);

        try {
            $this->runWebhookJob('order.completed', $payload);
            $this->fail('Expected RuntimeException (slot unavailable) was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Slot unavailable', $e->getMessage());
        }

        Queue::assertNothingPushed();
    }

    // ── Webhook order.refunded → BookingCancelled ──────────────────

    public function test_webhook_order_refunded_pushes_cancelled_jobs(): void
    {
        Queue::fake();

        $booking = $this->createBooking([
            'wc_order_id' => 99903,
        ]);

        $this->runWebhookJob('order.refunded', ['id' => 99903, 'status' => 'refunded']);

        Queue::assertPushed(PushNotificationToCarlitox::class, function ($job) use ($booking) {
            return $job->event === PushNotificationToCarlitox::EVENT_BOOKING_CANCELLED
                && $job->channel === PushNotificationToCarlitox::CHANNEL_EMAIL
                && $job->booking->id === $booking->id;
        });

        Queue::assertPushed(PushNotificationToCarlitox::class, function ($job) use ($booking) {
            return $job->event === PushNotificationToCarlitox::EVENT_BOOKING_CANCELLED
                && $job->channel === PushNotificationToCarlitox::CHANNEL_WHATSAPP
                && $job->booking->id === $booking->id;
        });
    }

    public function test_webhook_order_refunded_no_booking_pushes_nothing(): void
    {
        Queue::fake();

        $this->runWebhookJob('order.refunded', ['id' => 999999, 'status' => 'refunded']);

        Queue::assertNothingPushed();
    }
}
