<?php

namespace Tests\Feature\Api\V1;

use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\BookingStatus;
use App\Models\Client;
use App\Models\Location;
use App\Models\Provider;
use App\Models\Sale;
use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PaymentMethodTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private Client $client;

    private Provider $provider;

    private Location $location;

    private Service $service;

    private BookingStatus $status;

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

        $this->admin = User::factory()->create([
            'role' => UserRole::ADMIN,
        ]);
    }

    private function authenticate(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->admin->createToken('test-token', ['*'])->plainTextToken);
    }

    private function createBooking(): Booking
    {
        return Booking::create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'provider_id' => $this->provider->id,
            'location_id' => $this->location->id,
            'status_id' => $this->status->id,
            'start_time' => Carbon::tomorrow()->addHours(10),
            'end_time' => Carbon::tomorrow()->addHours(11),
            'price' => 50000,
        ]);
    }

    private function createSale(): Sale
    {
        return Sale::create([
            'client_id' => $this->client->id,
            'total' => 50000,
            'paid_amount' => 0,
        ]);
    }

    // ── 1.6: invalid payment_method rejected with 422 on write ──────

    public function test_store_rejects_invalid_payment_method(): void
    {
        $this->authenticate();
        $booking = $this->createBooking();

        $response = $this->postJson('/api/v1/sales', [
            'booking_id' => $booking->id,
            'payment_method' => 'tarjeta',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payment_method']);
    }

    public function test_update_rejects_invalid_payment_method(): void
    {
        $this->authenticate();
        $sale = $this->createSale();

        $response = $this->patchJson("/api/v1/sales/{$sale->id}", [
            'payment_method' => 'credit_card',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payment_method']);
    }

    public function test_register_transaction_rejects_invalid_payment_method(): void
    {
        $this->authenticate();
        $sale = $this->createSale();

        $response = $this->postJson("/api/v1/sales/{$sale->id}/transactions", [
            'amount' => 10000,
            'payment_method' => 'bitcoin',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payment_method']);
    }

    // ── 1.3: normalization migration reconciles legacy values ───────

    public function test_normalization_migration_maps_legacy_values(): void
    {
        DB::table('sales')->insert([
            ['total' => 1000, 'payment_method' => 'tarjeta'],
            ['total' => 1000, 'payment_method' => 'credit_card'],
            ['total' => 1000, 'payment_method' => 'débito'],
            ['total' => 1000, 'payment_method' => 'cheque'],
            ['total' => 1000, 'payment_method' => null],
        ]);

        $migration = require database_path('migrations/2026_08_24_000000_normalize_legacy_payment_methods.php');
        $migration->up();

        $values = DB::table('sales')->orderBy('id')->pluck('payment_method')->all();

        $this->assertSame(['crédito', 'online', 'débito', 'otro', null], $values);
    }

    // ── 1.4: models cast payment_method to PaymentMethod ─────────────

    public function test_sale_casts_payment_method_to_enum(): void
    {
        $sale = Sale::create([
            'client_id' => $this->client->id,
            'total' => 50000,
            'paid_amount' => 0,
            'payment_method' => 'efectivo',
        ]);

        $this->assertInstanceOf(PaymentMethod::class, $sale->payment_method);
        $this->assertSame(PaymentMethod::EFECTIVO, $sale->payment_method);
    }

    public function test_sale_payment_method_is_nullable(): void
    {
        $sale = Sale::create([
            'client_id' => $this->client->id,
            'total' => 50000,
            'paid_amount' => 0,
        ]);

        $this->assertNull($sale->payment_method);
    }

    public function test_sale_transaction_casts_payment_method_to_enum(): void
    {
        $sale = $this->createSale();

        $transaction = $sale->transactions()->create([
            'amount' => 10000,
            'payment_method' => 'transferencia',
            'paid_at' => now(),
        ]);

        $this->assertInstanceOf(PaymentMethod::class, $transaction->payment_method);
        $this->assertSame(PaymentMethod::TRANSFERENCIA, $transaction->payment_method);
    }
}
