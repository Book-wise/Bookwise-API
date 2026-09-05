<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class SaleDeletionTest extends TestCase
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
            'email' => 'sale-deletion@test.com',
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

    // ── 3.1: soft delete semantics ─────────────────────────────────

    public function test_admin_can_soft_delete_sale(): void
    {
        $sale = $this->createSale();
        $other = $this->createSale();

        $this->authenticateAs($this->admin);

        $response = $this->deleteJson("/api/v1/sales/{$sale->id}");

        $response->assertStatus(204);
        $this->assertSoftDeleted('sales', ['id' => $sale->id]);

        // Hidden from reads: show returns 404
        $this->getJson("/api/v1/sales/{$sale->id}")->assertStatus(404);

        // Hidden from index listing while the other sale remains visible
        $index = $this->getJson('/api/v1/sales');
        $index->assertStatus(200);
        $saleIds = collect($index->json())->pluck('id')->all();
        $this->assertNotContains($sale->id, $saleIds);
        $this->assertContains($other->id, $saleIds);
    }

    public function test_delete_non_existent_sale_returns_404(): void
    {
        $this->authenticateAs($this->admin);

        $this->deleteJson('/api/v1/sales/999999')->assertStatus(404);
    }

    public function test_delete_already_deleted_sale_returns_404(): void
    {
        $sale = $this->createSale();

        $this->authenticateAs($this->admin);

        $this->deleteJson("/api/v1/sales/{$sale->id}")->assertStatus(204);
        $this->deleteJson("/api/v1/sales/{$sale->id}")->assertStatus(404);
    }

    public function test_non_admin_cannot_delete_sale(): void
    {
        $sale = $this->createSale();

        $this->authenticateAs($this->provider);

        $this->deleteJson("/api/v1/sales/{$sale->id}")->assertStatus(403);

        // Sale untouched: still present and not soft-deleted
        $this->assertDatabaseHas('sales', ['id' => $sale->id]);
        $this->assertNull($sale->fresh()->deleted_at);
    }

    public function test_transactions_survive_soft_delete(): void
    {
        $sale = $this->createSale();
        $transaction = $sale->transactions()->create([
            'amount' => 10000,
            'paid_at' => now(),
        ]);

        $this->authenticateAs($this->admin);

        $this->deleteJson("/api/v1/sales/{$sale->id}")->assertStatus(204);

        $this->assertSoftDeleted('sales', ['id' => $sale->id]);
        $this->assertDatabaseHas('sale_transactions', [
            'id' => $transaction->id,
            'sale_id' => $sale->id,
        ]);
    }

    public function test_re_sync_same_wc_order_id_succeeds_after_soft_delete(): void
    {
        $sale = $this->createSale(['wc_order_id' => 555111]);

        $this->authenticateAs($this->admin);

        $this->deleteJson("/api/v1/sales/{$sale->id}")->assertStatus(204);
        $this->assertSoftDeleted('sales', ['id' => $sale->id]);

        // A new sale with the same wc_order_id must NOT hit the unique constraint
        $resynced = $this->createSale(['wc_order_id' => 555111]);
        $this->assertNotNull($resynced->id);
        $this->assertDatabaseCount('sales', 2);
    }

    public function test_generated_wc_order_id_active_column_tracks_deletion(): void
    {
        $sale = $this->createSale(['wc_order_id' => 777]);

        // Active sale: generated column mirrors wc_order_id
        $this->assertDatabaseHas('sales', [
            'id' => $sale->id,
            'wc_order_id_active' => 777,
        ]);

        $sale->delete();

        // Soft-deleted sale: generated column is NULL, freeing the unique key
        $this->assertDatabaseHas('sales', [
            'id' => $sale->id,
            'wc_order_id_active' => null,
        ]);
    }
}
