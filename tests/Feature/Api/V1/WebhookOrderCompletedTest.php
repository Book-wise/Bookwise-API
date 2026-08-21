<?php

namespace Tests\Feature\Api\V1;

use App\Jobs\ProcessWooCommerceWebhook;
use App\Models\Booking;
use App\Models\BookingStatus;
use App\Models\Client;
use App\Models\Location;
use App\Models\Sale;
use App\Models\Service;
use App\Models\WoocommerceWebhooksLog;
use App\Services\BookingService;
use App\Services\ClientService;
use App\Services\SaleService;
use App\Services\WooCommerceCustomerService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

class WebhookOrderCompletedTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Service $service;

    private Location $location;

    private BookingStatus $confirmedStatus;

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
    }

    // ── Helpers ─────────────────────────────────────────────────────

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
                        ['key' => '_kinesilk_slot_start', 'value' => Carbon::tomorrow()->addHours(10)->toIso8601String()],
                        ['key' => '_kinesilk_slot_end', 'value' => Carbon::tomorrow()->addHours(11)->toIso8601String()],
                        ['key' => '_kinesilk_location_id', 'value' => (string) $this->location->id],
                        ['key' => '_kinesilk_service_id', 'value' => (string) $this->service->id],
                        ['key' => '_kinesilk_duration_minutes', 'value' => '60'],
                    ],
                ],
            ],
        ];

        return array_replace_recursive($defaults, $overrides);
    }

    private function signPayload(array $payload): array
    {
        $json = json_encode($payload);
        $signature = base64_encode(hash_hmac('sha256', $json, $this->webhookSecret, true));

        return [
            'X-WC-Webhook-Signature' => $signature,
            'X-WC-Webhook-Topic' => 'order.updated',
        ];
    }

    private function sendWebhook(array $payload, array $headers = []): TestResponse
    {
        $defaultHeaders = $this->signPayload($payload);

        return $this->withHeaders(array_merge($defaultHeaders, $headers))
            ->postJson('/api/v1/webhooks/woocommerce', $payload);
    }

    /**
     * Helper: run the job synchronously by calling handle() directly.
     * Returns the refreshed log entry for status assertions.
     */
    private function runJob(string $event, array $payloadData): WoocommerceWebhooksLog
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

    // ── Controller: HMAC validation ─────────────────────────────────

    public function test_invalid_signature_returns_401(): void
    {
        $payload = $this->buildPayload();

        $response = $this->withHeaders([
            'X-WC-Webhook-Signature' => 'invalid-signature',
            'X-WC-Webhook-Topic' => 'order.updated',
        ])->postJson('/api/v1/webhooks/woocommerce', $payload);

        $response->assertStatus(401);
        $response->assertJson([
            'error' => 'unauthorized',
        ]);
    }

    // ── Controller: Job dispatch ────────────────────────────────────

    public function test_valid_webhook_dispatches_job(): void
    {
        Queue::fake();

        $payload = $this->buildPayload();
        $response = $this->sendWebhook($payload);

        $response->assertStatus(200);
        $response->assertJson(['received' => true]);

        Queue::assertPushed(ProcessWooCommerceWebhook::class, function ($job) {
            return $job->event === 'order.updated';
        });
    }

    public function test_customer_created_dispatches_job(): void
    {
        Queue::fake();

        $payload = [
            'id' => 999,
            'email' => 'wc-customer@test.com',
            'first_name' => 'WC',
            'last_name' => 'Customer',
            'billing' => [
                'first_name' => 'WC',
                'last_name' => 'Customer',
                'phone' => '+1234567890',
            ],
        ];

        $json = json_encode($payload);
        $signature = base64_encode(hash_hmac('sha256', $json, $this->webhookSecret, true));

        $response = $this->withHeaders([
            'X-WC-Webhook-Signature' => $signature,
            'X-WC-Webhook-Topic' => 'customer.created',
        ])->postJson('/api/v1/webhooks/woocommerce', $payload);

        $response->assertStatus(200);
        $response->assertJson(['received' => true]);

        Queue::assertPushed(ProcessWooCommerceWebhook::class, function ($job) {
            return $job->event === 'customer.created';
        });
    }

    // ── Happy path (job) ────────────────────────────────────────────

    public function test_job_creates_booking_sale_and_transaction(): void
    {
        $payload = $this->buildPayload();
        $log = $this->runJob('order.completed', $payload);

        // Verify log status
        $this->assertSame('processed', $log->status);

        // Verify client was created
        $this->assertDatabaseHas('clients', [
            'email' => 'customer@test.com',
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        $client = Client::where('email', 'customer@test.com')->first();

        // Verify booking was created
        $this->assertDatabaseHas('bookings', [
            'wc_order_id' => 12345,
            'client_id' => $client->id,
            'service_id' => $this->service->id,
            'location_id' => $this->location->id,
            'status_id' => $this->confirmedStatus->id,
        ]);

        $booking = Booking::where('wc_order_id', 12345)->first();
        $this->assertNotNull($booking->start_time);
        $this->assertNotNull($booking->end_time);

        // Verify sale was created
        $this->assertDatabaseHas('sales', [
            'wc_order_id' => 12345,
            'booking_id' => $booking->id,
            'client_id' => $client->id,
            'total' => 50000,
            'paid_amount' => 50000,
            'payment_method' => 'credit_card',
        ]);

        // Verify sale transaction was created
        $sale = Sale::where('wc_order_id', 12345)->first();
        $this->assertDatabaseHas('sale_transactions', [
            'sale_id' => $sale->id,
            'amount' => 50000,
            'payment_method' => 'credit_card',
        ]);

        // Verify webhook log was updated
        $this->assertDatabaseHas('woocommerce_webhooks_log', [
            'wc_order_id' => 12345,
            'status' => 'processed',
        ]);
    }

    // ── Idempotent replay (job) ─────────────────────────────────────

    public function test_job_idempotent_replay_does_not_duplicate(): void
    {
        $payload = $this->buildPayload();

        // First run
        $firstLog = $this->runJob('order.completed', $payload);
        $this->assertSame('processed', $firstLog->status);

        $bookingCount = Booking::count();
        $saleCount = Sale::count();

        // Second run — same payload
        $secondLog = $this->runJob('order.completed', $payload);
        $this->assertSame('processed', $secondLog->status);

        // Should NOT create duplicates
        $this->assertDatabaseCount('bookings', $bookingCount);
        $this->assertDatabaseCount('sales', $saleCount);
        $this->assertDatabaseCount('sale_transactions', $saleCount);
    }

    // ── Slot unavailable (job) ──────────────────────────────────────

    public function test_job_slot_unavailable_fails_log(): void
    {
        $payload = $this->buildPayload();
        $slotStart = Carbon::tomorrow()->addHours(10);
        $slotEnd = Carbon::tomorrow()->addHours(11);

        // Create an overlapping active booking
        $existingClient = Client::create([
            'first_name' => 'Existing',
            'last_name' => 'Client',
            'email' => 'existing@test.com',
            'active' => true,
        ]);
        Booking::create([
            'client_id' => $existingClient->id,
            'service_id' => $this->service->id,
            'location_id' => $this->location->id,
            'status_id' => $this->confirmedStatus->id,
            'start_time' => $slotStart,
            'end_time' => $slotEnd,
            'price' => 50000,
        ]);

        $log = WoocommerceWebhooksLog::create([
            'event' => 'order.completed',
            'wc_order_id' => $payload['id'],
            'wc_entity_id' => $payload['id'],
            'entity_type' => 'order',
            'payload' => json_encode($payload),
            'status' => 'received',
        ]);

        $job = new ProcessWooCommerceWebhook(
            event: 'order.completed',
            payload: json_encode($payload),
            logId: $log->id,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Slot unavailable');
        $job->handle(
            app(WooCommerceCustomerService::class),
            app(ClientService::class),
            app(BookingService::class),
            app(SaleService::class),
        );
    }

    // ── Missing billing.email (job) ─────────────────────────────────

    public function test_job_missing_billing_email_fails_log(): void
    {
        $payload = $this->buildPayload();
        $payload['billing'] = [
            'first_name' => 'John',
            'last_name' => 'Doe',
        ];

        $log = WoocommerceWebhooksLog::create([
            'event' => 'order.completed',
            'wc_order_id' => $payload['id'],
            'wc_entity_id' => $payload['id'],
            'entity_type' => 'order',
            'payload' => json_encode($payload),
            'status' => 'received',
        ]);

        $job = new ProcessWooCommerceWebhook(
            event: 'order.completed',
            payload: json_encode($payload),
            logId: $log->id,
        );

        try {
            $job->handle(
                app(WooCommerceCustomerService::class),
                app(ClientService::class),
                app(BookingService::class),
                app(SaleService::class),
            );
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Missing billing.email', $e->getMessage());
        }

        // Log should be marked as failed
        $this->assertSame('failed', $log->fresh()->status);
    }

    // ── Missing line-item meta (job) ────────────────────────────────

    public function test_job_missing_line_item_meta_does_not_create_booking(): void
    {
        $payload = $this->buildPayload();
        $payload['line_items'] = [
            [
                'id' => 1,
                'name' => 'Simple Product',
                'meta_data' => [],
            ],
        ];

        $log = $this->runJob('order.completed', $payload);

        $this->assertSame('processed', $log->status);

        // No booking or sale should be created
        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('sales', 0);
    }

    // ── No line_items at all (job) ─────────────────────────────────

    public function test_job_no_line_items_does_not_create_booking(): void
    {
        $payload = $this->buildPayload();
        $payload['line_items'] = [];

        $log = $this->runJob('order.completed', $payload);

        $this->assertSame('processed', $log->status);
        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('sales', 0);
    }

    // ── Existing client upsert (job) ────────────────────────────────

    public function test_job_existing_client_is_updated_not_duplicated(): void
    {
        // Pre-create a client with the same email
        $client = Client::create([
            'first_name' => 'Old',
            'last_name' => 'Name',
            'email' => 'customer@test.com',
            'active' => true,
        ]);

        $payload = $this->buildPayload();
        $log = $this->runJob('order.completed', $payload);

        $this->assertSame('processed', $log->status);

        // Only one client with this email
        $this->assertDatabaseCount('clients', 1);

        // Client was updated with new data
        $client->refresh();
        $this->assertSame('John', $client->first_name);
        $this->assertSame('Doe', $client->last_name);
    }

    // ── Customer created event (job) ────────────────────────────────

    public function test_job_customer_created_syncs_client(): void
    {
        $payloadData = [
            'id' => 999,
            'email' => 'wc-customer@test.com',
            'first_name' => 'WC',
            'last_name' => 'Customer',
            'billing' => [
                'first_name' => 'WC',
                'last_name' => 'Customer',
                'phone' => '+1234567890',
            ],
        ];

        $log = WoocommerceWebhooksLog::create([
            'event' => 'customer.created',
            'wc_entity_id' => $payloadData['id'],
            'entity_type' => 'customer',
            'payload' => json_encode($payloadData),
            'status' => 'received',
        ]);

        $job = new ProcessWooCommerceWebhook(
            event: 'customer.created',
            payload: json_encode($payloadData),
            logId: $log->id,
        );

        $job->handle(
            app(WooCommerceCustomerService::class),
            app(ClientService::class),
            app(BookingService::class),
            app(SaleService::class),
        );

        $this->assertDatabaseHas('clients', ['wc_customer_id' => 999]);

        $log->refresh();
        $this->assertSame('processed', $log->status);
    }
}
