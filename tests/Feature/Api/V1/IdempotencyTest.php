<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\BlockedSlot;
use App\Models\Booking;
use App\Models\BookingStatus;
use App\Models\Client;
use App\Models\ClientPack;
use App\Models\Location;
use App\Models\PackSession;
use App\Models\Provider;
use App\Models\Sale;
use App\Models\Service;
use App\Models\ServicePack;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IdempotencyTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private Client $client;

    private Provider $provider;

    private Location $location;

    private Service $service;

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

        $this->admin = User::factory()->create([
            'role' => UserRole::ADMIN,
        ]);
    }

    private function authenticate(): void
    {
        $token = $this->admin->createToken('test-token', ['*']);
        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken);
    }

    private function keyHeader(string $key): array
    {
        return ['Idempotency-Key' => $key];
    }

    // ── 5.3: retry transaction returns cached ───────────────────────

    public function test_transaction_retry_returns_cached(): void
    {
        $this->authenticate();

        $sale = Sale::create([
            'client_id' => $this->client->id,
            'total' => 50000,
            'paid_amount' => 0,
        ]);

        $idempotencyKey = 'test-txn-retry-'.str()->uuid();

        // First request — create the transaction
        $firstResponse = $this->withHeaders($this->keyHeader($idempotencyKey))
            ->postJson("/api/v1/sales/{$sale->id}/transactions", [
                'amount' => 30000,
            ]);

        $firstResponse->assertStatus(201);
        $firstBody = $firstResponse->json();

        // Second request — same key, should return cached
        $secondResponse = $this->withHeaders($this->keyHeader($idempotencyKey))
            ->postJson("/api/v1/sales/{$sale->id}/transactions", [
                'amount' => 30000,
            ]);

        $secondResponse->assertStatus(201);
        $secondBody = $secondResponse->json();

        // Should have the same data
        $this->assertSame(30000, (int) $secondBody['data']['amount']);

        // Only one transaction row exists (no duplicate from cache)
        $this->assertDatabaseCount('sale_transactions', 1);
    }

    // ── 5.4: concurrent ClientPack consumption ──────────────────────

    public function test_pack_consumption_prevents_double_use(): void
    {
        $this->authenticate();

        $servicePack = ServicePack::create([
            'service_id' => $this->service->id,
            'name' => 'Test Pack',
            'total_sessions' => 1,
            'price' => 50000,
        ]);

        $clientPack = ClientPack::create([
            'client_id' => $this->client->id,
            'service_pack_id' => $servicePack->id,
            'total_sessions' => 1,
            'used_sessions' => 0,
            'status' => 'active',
        ]);

        PackSession::create([
            'client_pack_id' => $clientPack->id,
            'session_number' => 1,
            'status' => 'pending',
        ]);

        $pendingStatus = BookingStatus::create([
            'name' => 'Pending',
            'is_cancellation' => false,
        ]);

        $booking = Booking::create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'provider_id' => $this->provider->id,
            'location_id' => $this->location->id,
            'status_id' => $pendingStatus->id,
            'start_time' => Carbon::tomorrow()->addHours(10),
            'end_time' => Carbon::tomorrow()->addHours(11),
            'price' => 50000,
        ]);

        // First consumption should succeed
        $firstResponse = $this->withHeaders($this->keyHeader('pack-consume-1'))
            ->patchJson("/api/v1/client-packs/{$clientPack->id}/use", [
                'booking_id' => $booking->id,
            ]);

        $firstResponse->assertStatus(200);

        // Second consumption — no sessions remaining
        $secondResponse = $this->withHeaders($this->keyHeader('pack-consume-2'))
            ->patchJson("/api/v1/client-packs/{$clientPack->id}/use", [
                'booking_id' => $booking->id,
            ]);

        $secondResponse->assertStatus(422);
        $this->assertTrue(
            $secondResponse->json('error') === 'pack_not_active'
            || $secondResponse->json('error') === 'no_sessions_remaining',
            'Expected pack_not_active or no_sessions_remaining, got: '.$secondResponse->json('error')
        );
    }

    // ── 5.5: concurrent Booking creation ────────────────────────────

    public function test_duplicate_booking_returns_overlap_conflict(): void
    {
        $this->authenticate();

        $bookingStatus = BookingStatus::create([
            'name' => 'Confirmed',
            'is_cancellation' => false,
        ]);

        $startTime = Carbon::tomorrow()->addHours(10);
        $endTime = Carbon::tomorrow()->addHours(11);

        // First booking should succeed
        $firstResponse = $this->withHeaders($this->keyHeader('booking-1'))
            ->postJson('/api/v1/bookings', [
                'start_time' => $startTime->toIso8601String(),
                'service_id' => $this->service->id,
                'provider_id' => $this->provider->id,
                'client_id' => $this->client->id,
                'location_id' => $this->location->id,
                'status_id' => $bookingStatus->id,
            ]);

        $firstResponse->assertStatus(201);

        // Second booking overlapping same time/provider should get 409
        $secondResponse = $this->withHeaders($this->keyHeader('booking-2'))
            ->postJson('/api/v1/bookings', [
                'start_time' => $startTime->toIso8601String(),
                'service_id' => $this->service->id,
                'provider_id' => $this->provider->id,
                'client_id' => $this->client->id,
                'location_id' => $this->location->id,
                'status_id' => $bookingStatus->id,
            ]);

        $secondResponse->assertStatus(409);
        $secondResponse->assertJson([
            'error' => 'conflict',
        ]);
    }

    // ── 5.6: duplicate webhook delivery with upsert() ──────────────

    public function test_duplicate_webhook_upsert_does_not_duplicate_sale(): void
    {
        $wcOrderId = 999888;

        // Simulate first webhook delivery
        Sale::upsert(
            [[
                'wc_order_id' => $wcOrderId,
                'booking_id' => null,
                'total' => 50000,
                'payment_method' => 'credit_card',
                'paid_at' => now(),
            ]],
            ['wc_order_id'],
            ['total', 'payment_method', 'paid_at']
        );

        $this->assertDatabaseCount('sales', 1);

        // Simulate duplicate webhook delivery
        Sale::upsert(
            [[
                'wc_order_id' => $wcOrderId,
                'booking_id' => null,
                'total' => 50000,
                'payment_method' => 'credit_card',
                'paid_at' => now(),
            ]],
            ['wc_order_id'],
            ['total', 'payment_method', 'paid_at']
        );

        // Still only one sale
        $this->assertDatabaseCount('sales', 1);

        // Verify the sale exists
        $this->assertDatabaseHas('sales', [
            'wc_order_id' => $wcOrderId,
            'total' => 50000,
        ]);
    }

    // ── 5.7: in-flight request returns 409 ──────────────────────────

    public function test_in_flight_request_returns_conflict(): void
    {
        $this->authenticate();

        $idempotencyKey = 'test-inflight-'.str()->uuid();
        $endpoint = 'POST /v1/clients';

        // Manually insert a processing entry (response_status = null)
        DB::table('idempotency_keys')->insert([
            'key' => $idempotencyKey,
            'endpoint' => $endpoint,
            'method' => 'POST',
            'request_hash' => md5(json_encode(['first_name' => 'In', 'last_name' => 'Flight'])),
            'response_status' => null, // null = in-flight
            'response_body' => null,
            'expires_at' => Carbon::now()->addMinutes(5),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        // Send a request with the same key — should see in-flight and return 409
        $response = $this->withHeaders($this->keyHeader($idempotencyKey))
            ->postJson('/api/v1/clients', [
                'first_name' => 'In',
                'last_name' => 'Flight',
                'email' => 'inflight@test.com',
            ]);

        $response->assertStatus(409);
        $response->assertJson([
            'error' => 'conflict',
        ]);
    }

    // ── Additional: P2 optional key tests ───────────────────────────

    public function test_client_store_without_key_still_works(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/v1/clients', [
            'first_name' => 'No',
            'last_name' => 'Key',
            'email' => 'nokey@test.com',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('clients', ['email' => 'nokey@test.com']);
    }

    public function test_client_store_with_key_is_idempotent(): void
    {
        $this->authenticate();

        $key = 'client-store-idemp-'.str()->uuid();

        $firstResponse = $this->withHeaders($this->keyHeader($key))
            ->postJson('/api/v1/clients', [
                'first_name' => 'Idempotent',
                'last_name' => 'Client',
                'email' => 'idemp-client@test.com',
            ]);

        $firstResponse->assertStatus(201);

        // Retry with same key — should get cached response
        $secondResponse = $this->withHeaders($this->keyHeader($key))
            ->postJson('/api/v1/clients', [
                'first_name' => 'Idempotent',
                'last_name' => 'Client',
                'email' => 'idemp-client@test.com',
            ]);

        $secondResponse->assertStatus(201);

        // Only one client with this email (cached response prevented duplicate)
        $clientsWithEmail = DB::table('clients')->where('email', 'idemp-client@test.com')->count();
        $this->assertSame(1, $clientsWithEmail, 'Expected exactly 1 client with this email');
    }

    // ── 5.8: P0 TOCTOU with different keys (transactions) ──────────

    public function test_toctou_different_keys_prevent_double_spend(): void
    {
        $this->authenticate();

        $sale = Sale::create([
            'client_id' => $this->client->id,
            'total' => 10000,
            'paid_amount' => 0,
        ]);

        $keyA = 'toctou-a-'.str()->uuid();
        $keyB = 'toctou-b-'.str()->uuid();

        // GIVEN: a Sale with remaining_amount = 10000
        // WHEN: first transaction with KEY_A for 8000
        $firstResponse = $this->withHeaders($this->keyHeader($keyA))
            ->postJson("/api/v1/sales/{$sale->id}/transactions", [
                'amount' => 8000,
            ]);

        // THEN: it succeeds
        $firstResponse->assertStatus(201);

        // WHEN: second transaction with KEY_B (different key) for 8000
        $secondResponse = $this->withHeaders($this->keyHeader($keyB))
            ->postJson("/api/v1/sales/{$sale->id}/transactions", [
                'amount' => 8000,
            ]);

        // THEN: it fails because remaining is now 2000
        $secondResponse->assertStatus(422);
        $secondResponse->assertJson([
            'error' => 'amount_exceeds_remaining',
        ]);
    }

    // ── 5.9: P1 Booking creation with key retry ─────────────────────

    public function test_booking_creation_with_key_retry_returns_cached(): void
    {
        $this->authenticate();

        $bookingStatus = BookingStatus::create([
            'name' => 'Confirmed',
            'is_cancellation' => false,
        ]);

        $startTime = Carbon::tomorrow()->addHours(10);
        $endTime = Carbon::tomorrow()->addHours(11);
        $key = 'booking-create-retry-'.str()->uuid();

        // GIVEN: a valid booking request with Idempotency-Key=KEY_C
        // WHEN: first request creates the booking
        $firstResponse = $this->withHeaders($this->keyHeader($key))
            ->postJson('/api/v1/bookings', [
                'start_time' => $startTime->toIso8601String(),
                'end_time' => $endTime->toIso8601String(),
                'service_id' => $this->service->id,
                'provider_id' => $this->provider->id,
                'client_id' => $this->client->id,
                'location_id' => $this->location->id,
                'status_id' => $bookingStatus->id,
            ]);

        $firstResponse->assertStatus(201);
        $firstBody = $firstResponse->json();
        $bookingId = $firstBody['data']['id'];

        // WHEN: retry with same key
        $secondResponse = $this->withHeaders($this->keyHeader($key))
            ->postJson('/api/v1/bookings', [
                'start_time' => $startTime->toIso8601String(),
                'end_time' => $endTime->toIso8601String(),
                'service_id' => $this->service->id,
                'provider_id' => $this->provider->id,
                'client_id' => $this->client->id,
                'location_id' => $this->location->id,
                'status_id' => $bookingStatus->id,
            ]);

        // THEN: cached response with same booking data, no duplicate created
        $secondResponse->assertStatus(201);
        $secondBody = $secondResponse->json();
        $this->assertSame($bookingId, $secondBody['data']['id']);
        $this->assertDatabaseCount('bookings', 1);
    }

    // ── 5.10: P1 Booking cancellation idempotency ──────────────────

    public function test_booking_cancel_with_key_succeeds(): void
    {
        $this->authenticate();

        $status = BookingStatus::create(['name' => 'Confirmed', 'is_cancellation' => false]);
        $cancelStatus = BookingStatus::create(['name' => 'Cancelled', 'is_cancellation' => true]);

        $booking = Booking::create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'provider_id' => $this->provider->id,
            'location_id' => $this->location->id,
            'status_id' => $status->id,
            'start_time' => Carbon::tomorrow()->addHours(10),
            'end_time' => Carbon::tomorrow()->addHours(11),
            'price' => 50000,
        ]);

        $key = 'cancel-happy-'.str()->uuid();

        // GIVEN: a booking with status != cancelled
        // WHEN: PATCH /v1/bookings/{id}/cancel with Idempotency-Key=KEY_D
        $response = $this->withHeaders($this->keyHeader($key))
            ->patchJson("/api/v1/bookings/{$booking->id}/cancel");

        // THEN: returns 200 with cancelled status
        $response->assertStatus(200);
        $this->assertSame($cancelStatus->id, $response->json('data.status.id'));
    }

    public function test_booking_cancel_retry_with_same_key_returns_cached(): void
    {
        $this->authenticate();

        $status = BookingStatus::create(['name' => 'Confirmed', 'is_cancellation' => false]);
        BookingStatus::create(['name' => 'Cancelled', 'is_cancellation' => true]);

        $booking = Booking::create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'provider_id' => $this->provider->id,
            'location_id' => $this->location->id,
            'status_id' => $status->id,
            'start_time' => Carbon::tomorrow()->addHours(10),
            'end_time' => Carbon::tomorrow()->addHours(11),
            'price' => 50000,
        ]);

        $key = 'cancel-retry-'.str()->uuid();

        // GIVEN: first cancel succeeds
        $firstResponse = $this->withHeaders($this->keyHeader($key))
            ->patchJson("/api/v1/bookings/{$booking->id}/cancel");
        $firstResponse->assertStatus(200);

        // WHEN: retry cancel with same key
        $secondResponse = $this->withHeaders($this->keyHeader($key))
            ->patchJson("/api/v1/bookings/{$booking->id}/cancel");

        // THEN: cached 200 response, not an "already cancelled" error
        $secondResponse->assertStatus(200);

        // AND: only 1 status_history row (the first cancellation, not a second entry)
        $this->assertDatabaseCount('booking_status_history', 1);
    }

    public function test_booking_double_cancel_without_key_returns_error(): void
    {
        $this->authenticate();

        $status = BookingStatus::create(['name' => 'Confirmed', 'is_cancellation' => false]);
        BookingStatus::create(['name' => 'Cancelled', 'is_cancellation' => true]);

        $booking = Booking::create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'provider_id' => $this->provider->id,
            'location_id' => $this->location->id,
            'status_id' => $status->id,
            'start_time' => Carbon::tomorrow()->addHours(10),
            'end_time' => Carbon::tomorrow()->addHours(11),
            'price' => 50000,
        ]);

        // GIVEN: first cancel (no key) succeeds
        $firstResponse = $this->patchJson("/api/v1/bookings/{$booking->id}/cancel");
        $firstResponse->assertStatus(200);

        // WHEN: second cancel (no key) is attempted
        $secondResponse = $this->patchJson("/api/v1/bookings/{$booking->id}/cancel");

        // THEN: 422 "already cancelled" — lockForUpdate saw the mutation
        $secondResponse->assertStatus(422);
        $secondResponse->assertJson([
            'error' => 'already_cancelled',
        ]);
    }

    // ── 5.11: P1 Sale creation with key retry ───────────────────────

    public function test_sale_creation_with_key_retry_returns_cached(): void
    {
        $this->authenticate();

        $status = BookingStatus::create(['name' => 'Confirmed', 'is_cancellation' => false]);

        $booking = Booking::create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'provider_id' => $this->provider->id,
            'location_id' => $this->location->id,
            'status_id' => $status->id,
            'start_time' => Carbon::tomorrow()->addHours(10),
            'end_time' => Carbon::tomorrow()->addHours(11),
            'price' => 50000,
        ]);

        $key = 'sale-create-retry-'.str()->uuid();

        // GIVEN: a booking exists
        // WHEN: POST /v1/sales with booking_id and KEY_E
        $firstResponse = $this->withHeaders($this->keyHeader($key))
            ->postJson('/api/v1/sales', [
                'booking_id' => $booking->id,
            ]);

        // THEN: sale created successfully
        $firstResponse->assertStatus(201);

        // WHEN: retry with same key
        $secondResponse = $this->withHeaders($this->keyHeader($key))
            ->postJson('/api/v1/sales', [
                'booking_id' => $booking->id,
            ]);

        // THEN: cached response, no duplicate sale
        $secondResponse->assertStatus(201);
        $this->assertDatabaseCount('sales', 1);
    }

    // ── 5.12: P1 Webhook customer upsert idempotency ────────────────

    public function test_duplicate_webhook_customer_upsert_does_not_duplicate_client(): void
    {
        $wcCustomerId = 123;
        $secret = 'test-webhook-secret';

        config(['services.woocommerce.webhook_secret' => $secret]);

        $data = [
            'id' => $wcCustomerId,
            'email' => 'wc-customer@test.com',
            'first_name' => 'WC',
            'last_name' => 'Customer',
            'billing' => [
                'first_name' => 'WC',
                'last_name' => 'Customer',
                'phone' => '+1234567890',
            ],
        ];

        $payload = json_encode($data);
        $signature = base64_encode(hash_hmac('sha256', $payload, $secret, true));

        // GIVEN: a WooCommerce webhook with customer.created event
        // WHEN: first webhook delivery
        $firstResponse = $this->withHeaders([
            'X-WC-Webhook-Signature' => $signature,
            'X-WC-Webhook-Topic' => 'customer.created',
        ])->postJson('/api/v1/webhooks/woocommerce', $data);

        // THEN: returns 200 and creates a Client
        $firstResponse->assertStatus(200);
        $this->assertDatabaseHas('clients', ['wc_customer_id' => $wcCustomerId]);

        // WHEN: duplicate webhook delivery with same payload
        $secondResponse = $this->withHeaders([
            'X-WC-Webhook-Signature' => $signature,
            'X-WC-Webhook-Topic' => 'customer.created',
        ])->postJson('/api/v1/webhooks/woocommerce', $data);

        // THEN: still only 1 Client with this wc_customer_id
        $secondResponse->assertStatus(200);
        $clientsWithWcId = DB::table('clients')->where('wc_customer_id', $wcCustomerId)->count();
        $this->assertSame(1, $clientsWithWcId, 'Expected exactly 1 client with wc_customer_id='.$wcCustomerId);
    }

    // ── 5.13: P2 Client duplicate email ─────────────────────────────

    public function test_client_duplicate_email_returns_validation_error(): void
    {
        $this->authenticate();

        // GIVEN: a Client with a specific email
        Client::create([
            'first_name' => 'Existing',
            'last_name' => 'Client',
            'email' => 'duplicate-email-p2@test.com',
            'active' => true,
        ]);

        // WHEN: POST /v1/clients with the same email
        $response = $this->postJson('/api/v1/clients', [
            'first_name' => 'Another',
            'last_name' => 'Client',
            'email' => 'duplicate-email-p2@test.com',
        ]);

        // THEN: 422 validation error on email
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    }

    // ── 5.14: P2 Blocked slot collision ────────────────────────────

    public function test_blocked_slot_collision_returns_conflict(): void
    {
        $this->authenticate();

        $startTime = Carbon::tomorrow()->addHours(10);
        $endTime = Carbon::tomorrow()->addHours(11);

        // GIVEN: a BlockedSlot for a provider+time
        BlockedSlot::create([
            'provider_id' => $this->provider->id,
            'location_id' => $this->location->id,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'reason' => 'First block',
        ]);

        // WHEN: POST /v1/blocked-slots with same provider+time
        $response = $this->postJson('/api/v1/blocked-slots', [
            'start_time' => $startTime->toIso8601String(),
            'end_time' => $endTime->toIso8601String(),
            'provider_id' => $this->provider->id,
            'location_id' => $this->location->id,
            'reason' => 'Second block',
        ]);

        // THEN: 409 slot_collision
        $response->assertStatus(409);
        $response->assertJson([
            'error' => 'slot_collision',
        ]);
    }
}
