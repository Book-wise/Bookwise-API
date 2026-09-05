<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Mail\ReceiptMail;
use App\Models\Client;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SaleReceiptTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private User $provider;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Client::create([
            'first_name' => 'Test',
            'last_name' => 'Client',
            'email' => 'receipt-test@test.com',
            'active' => true,
        ]);

        $this->admin = User::factory()->create([
            'role' => UserRole::ADMIN,
        ]);

        $this->provider = User::factory()->create([
            'role' => UserRole::PROVIDER,
        ]);
    }

    private function authenticateAs(User $user): void
    {
        $this->withHeader('Authorization', 'Bearer '.$user->createToken('test-token', ['*'])->plainTextToken);
    }

    private function createSale(array $attributes = []): Sale
    {
        return Sale::create(array_merge([
            'client_id' => $this->client->id,
            'total' => 50000,
            'paid_amount' => 0,
        ], $attributes));
    }

    // ── GET /sales/{id}/receipt ────────────────────────────────────

    public function test_get_receipt_returns_pdf(): void
    {
        $sale = $this->createSale();
        $this->authenticateAs($this->admin);

        $response = $this->getJson("/api/v1/sales/{$sale->id}/receipt");

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_get_receipt_requires_bearer_token(): void
    {
        $sale = $this->createSale();

        $response = $this->getJson("/api/v1/sales/{$sale->id}/receipt");

        $response->assertStatus(401);
    }

    public function test_get_receipt_works_with_incomplete_tenant_profile(): void
    {
        // Tenant with all nullable fields
        $tenant = Tenant::create([
            'business_name' => null,
            'business_rut' => null,
            'business_logo_url' => null,
        ]);

        $this->admin->update(['tenant_id' => $tenant->id]);

        $sale = $this->createSale();
        $this->authenticateAs($this->admin);

        $response = $this->getJson("/api/v1/sales/{$sale->id}/receipt");

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_get_receipt_returns_404_for_trashed_sale(): void
    {
        $sale = $this->createSale();
        $sale->delete();

        $this->authenticateAs($this->admin);

        $response = $this->getJson("/api/v1/sales/{$sale->id}/receipt");

        $response->assertStatus(404);
    }

    public function test_get_receipt_returns_404_for_nonexistent_sale(): void
    {
        $this->authenticateAs($this->admin);

        $response = $this->getJson('/api/v1/sales/999999/receipt');

        $response->assertStatus(404);
    }

    // ── POST /sales/{id}/receipt/send ──────────────────────────────

    public function test_send_receipt_returns_200_and_queues_mail(): void
    {
        Mail::fake();

        $sale = $this->createSale();
        $this->authenticateAs($this->admin);

        $response = $this->postJson("/api/v1/sales/{$sale->id}/receipt/send", [
            'email' => 'cliente@ejemplo.com',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Comprobante enviado']);

        Mail::assertQueued(ReceiptMail::class);
    }

    public function test_send_receipt_returns_422_without_email(): void
    {
        Mail::fake();

        $sale = $this->createSale();
        $this->authenticateAs($this->admin);

        $response = $this->postJson("/api/v1/sales/{$sale->id}/receipt/send", []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');

        Mail::assertNothingQueued();
    }

    public function test_send_receipt_returns_404_for_nonexistent_sale(): void
    {
        Mail::fake();

        $this->authenticateAs($this->admin);

        $response = $this->postJson('/api/v1/sales/999999/receipt/send', [
            'email' => 'cliente@ejemplo.com',
        ]);

        $response->assertStatus(404);

        Mail::assertNothingQueued();
    }

    public function test_non_admin_cannot_send_receipt(): void
    {
        Mail::fake();

        $sale = $this->createSale();
        $this->authenticateAs($this->provider);

        $response = $this->postJson("/api/v1/sales/{$sale->id}/receipt/send", [
            'email' => 'cliente@ejemplo.com',
        ]);

        $response->assertStatus(403);

        Mail::assertNothingQueued();
    }
}
