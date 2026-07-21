<?php

namespace Tests\Feature\Api\V1;

use App\Models\Booking;
use App\Models\BookingStatus;
use App\Models\Client;
use App\Models\Location;
use App\Models\Sale;
use App\Models\Service;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
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

    private function buildPayload(array $overrides = []): array
    {
        $defaults = [
            'id' => 12345,
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
            'X-WC-Webhook-Topic' => 'order.completed',
        ];
    }

    private function sendWebhook(array $payload, array $headers = []): TestResponse
    {
        $defaultHeaders = $this->signPayload($payload);

        return $this->withHeaders(array_merge($defaultHeaders, $headers))
            ->postJson('/api/v1/webhooks/woocommerce', $payload);
    }

    // ── Happy path ──────────────────────────────────────────────────

    public function test_happy_path_creates_booking_sale_and_transaction(): void
    {
        $payload = $this->buildPayload();

        $response = $this->sendWebhook($payload);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'booking_id',
            'sale_id',
            'client_id',
        ]);

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
        $this->assertDatabaseHas('sale_transactions', [
            'sale_id' => $response->json('sale_id'),
            'amount' => 50000,
            'payment_method' => 'credit_card',
        ]);

        // Verify webhook log was updated
        $this->assertDatabaseHas('woocommerce_webhooks_log', [
            'wc_order_id' => 12345,
            'status' => 'processed',
        ]);
    }

    // ── Idempotent replay ───────────────────────────────────────────

    public function test_idempotent_replay_returns_existing_records(): void
    {
        $payload = $this->buildPayload();

        // First request — creates everything
        $firstResponse = $this->sendWebhook($payload);
        $firstResponse->assertStatus(200);
        $firstBody = $firstResponse->json();

        // Second request — same payload, should return existing records
        $secondResponse = $this->sendWebhook($payload);
        $secondResponse->assertStatus(200);
        $secondBody = $secondResponse->json();

        // Should return the same booking and sale IDs
        $this->assertSame($firstBody['booking_id'], $secondBody['booking_id']);
        $this->assertSame($firstBody['sale_id'], $secondBody['sale_id']);
        $this->assertSame($firstBody['client_id'], $secondBody['client_id']);

        // Only one booking, one sale, one transaction
        $this->assertDatabaseCount('bookings', 1);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('sale_transactions', 1);
    }

    // ── Slot unavailable ────────────────────────────────────────────

    public function test_slot_unavailable_returns_409(): void
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

        $response = $this->sendWebhook($payload);

        $response->assertStatus(409);
        $response->assertJson([
            'error' => 'slot_unavailable',
        ]);

        // No sale should be created
        $this->assertDatabaseCount('sales', 0);
    }

    // ── Missing billing.email ──────────────────────────────────────

    public function test_missing_billing_email_returns_400(): void
    {
        $payload = $this->buildPayload();
        $payload['billing'] = [
            'first_name' => 'John',
            'last_name' => 'Doe',
        ];

        $response = $this->sendWebhook($payload);

        $response->assertStatus(400);
        $response->assertJson([
            'error' => 'validation_error',
            'detail' => 'Missing billing.email in payload.',
        ]);
    }

    // ── Missing line-item meta ──────────────────────────────────────

    public function test_missing_line_item_meta_returns_200_processed(): void
    {
        $payload = $this->buildPayload();
        $payload['line_items'] = [
            [
                'id' => 1,
                'name' => 'Simple Product',
                'meta_data' => [],
            ],
        ];

        $response = $this->sendWebhook($payload);

        $response->assertStatus(200);
        $this->assertDatabaseHas('woocommerce_webhooks_log', [
            'wc_order_id' => 12345,
            'status' => 'processed',
        ]);

        // No booking or sale should be created
        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('sales', 0);
    }

    // ── Invalid signature ──────────────────────────────────────────

    public function test_invalid_signature_returns_401(): void
    {
        $payload = $this->buildPayload();

        $response = $this->withHeaders([
            'X-WC-Webhook-Signature' => 'invalid-signature',
            'X-WC-Webhook-Topic' => 'order.completed',
        ])->postJson('/api/v1/webhooks/woocommerce', $payload);

        $response->assertStatus(401);
        $response->assertJson([
            'error' => 'unauthorized',
        ]);
    }

    public function test_missing_webhook_secret_returns_configuration_error(): void
    {
        config(['services.woocommerce.webhook_secret' => '']);

        $response = $this->sendWebhook($this->buildPayload());

        $response->assertStatus(503);
        $response->assertJson(['error' => 'configuration_error']);
        $this->assertDatabaseCount('woocommerce_webhooks_log', 0);
    }

    public function test_webhook_log_redacts_billing_personal_data(): void
    {
        $payload = $this->buildPayload();

        $this->sendWebhook($payload)->assertStatus(200);

        $log = \App\Models\WoocommerceWebhooksLog::firstOrFail();
        $storedPayload = $log->payload;

        $this->assertArrayNotHasKey('billing', $storedPayload);
        $this->assertStringNotContainsString($payload['billing']['email'], json_encode($storedPayload));
        $this->assertSame('order.completed', $storedPayload['topic']);
        $this->assertSame($payload['id'], $storedPayload['id']);
    }

    public function test_refund_cancels_once_without_creating_financial_reversal(): void
    {
        BookingStatus::create(['name' => 'Cancelled', 'is_cancellation' => true]);
        $payload = $this->buildPayload();
        $this->sendWebhook($payload)->assertStatus(200);

        $refundPayload = ['id' => $payload['id'], 'status' => 'refunded'];
        $json = json_encode($refundPayload);
        $signature = base64_encode(hash_hmac('sha256', $json, $this->webhookSecret, true));

        $headers = [
            'X-WC-Webhook-Signature' => $signature,
            'X-WC-Webhook-Topic' => 'order.refunded',
        ];

        $this->withHeaders($headers)->postJson('/api/v1/webhooks/woocommerce', $refundPayload)->assertStatus(200);
        $this->withHeaders($headers)->postJson('/api/v1/webhooks/woocommerce', $refundPayload)->assertStatus(200);

        $booking = Booking::where('wc_order_id', $payload['id'])->firstOrFail();
        $this->assertTrue((bool) $booking->status->is_cancellation);
        $this->assertDatabaseCount('sale_transactions', 1);
        $this->assertDatabaseCount('booking_status_history', 2);
        $this->assertDatabaseHas('woocommerce_webhooks_log', [
            'wc_order_id' => $payload['id'],
            'status' => 'processed',
        ]);
    }

    // ── Client already exists — upsert ──────────────────────────────

    public function test_existing_client_is_updated_not_duplicated(): void
    {
        // Pre-create a client with the same email
        $client = Client::create([
            'first_name' => 'Old',
            'last_name' => 'Name',
            'email' => 'customer@test.com',
            'active' => true,
        ]);

        $payload = $this->buildPayload();

        $response = $this->sendWebhook($payload);

        $response->assertStatus(200);

        // Only one client with this email
        $this->assertDatabaseCount('clients', 1);

        // Client was updated with new data
        $client->refresh();
        $this->assertSame('John', $client->first_name);
        $this->assertSame('Doe', $client->last_name);
    }

    // ── No line_items at all ───────────────────────────────────────

    public function test_no_line_items_returns_200_processed(): void
    {
        $payload = $this->buildPayload();
        $payload['line_items'] = [];

        $response = $this->sendWebhook($payload);

        $response->assertStatus(200);
        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('sales', 0);
    }

    // ── Existing customer events unchanged ──────────────────────────

    public function test_customer_created_still_works(): void
    {
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
        $this->assertDatabaseHas('clients', ['wc_customer_id' => 999]);
    }
}
