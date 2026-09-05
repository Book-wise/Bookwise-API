<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NotificationPrefsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const PREF_COLUMNS = [
        'email_new_booking',
        'email_booking_confirmation',
        'email_booking_cancellation',
        'whatsapp_reminder',
        'whatsapp_cancellation_confirmation',
    ];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => UserRole::ADMIN,
        ]);

        $token = $this->admin->createToken('test-token', ['*']);
        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken);
    }

    private function createClient(array $overrides = []): Client
    {
        return Client::create(array_merge([
            'first_name' => 'Juan',
            'last_name' => 'Perez',
            'email' => 'juan@test.com',
            'active' => true,
        ], $overrides));
    }

    // ── R1: Schema ─────────────────────────────────────────────────

    public function test_migration_adds_prefs_columns_defaulting_to_true(): void
    {
        foreach (self::PREF_COLUMNS as $column) {
            $this->assertTrue(
                Schema::hasColumn('clients', $column),
                "Expected clients table to have column {$column}."
            );
        }

        $client = $this->createClient()->fresh();

        $this->assertTrue($client->notifications_enabled);
        foreach (self::PREF_COLUMNS as $column) {
            $this->assertTrue(
                $client->{$column},
                "Expected client {$column} to default to true."
            );
        }
    }

    public function test_migration_adds_start_time_index_on_bookings(): void
    {
        $this->assertTrue(
            Schema::hasIndex('bookings', 'bookings_start_time_index'),
            'Expected bookings table to have a start_time index named bookings_start_time_index.'
        );
    }

    // ── R2 / SC1: Exposure via ClientResource ──────────────────────

    public function test_get_client_exposes_notifications_enabled_and_prefs(): void
    {
        $client = $this->createClient();

        $response = $this->getJson("/api/v1/clients/{$client->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $client->id)
            ->assertJsonPath('data.notifications_enabled', true);

        $prefs = $response->json('data.notification_prefs');
        $this->assertIsArray($prefs);
        $this->assertCount(5, $prefs);
        foreach (self::PREF_COLUMNS as $column) {
            $this->assertTrue(
                $prefs[$column],
                "Expected notification_prefs.{$column} to be exposed as true."
            );
        }
    }

    // ── R3 / SC2: Partial PATCH ────────────────────────────────────

    public function test_patch_partial_pref_updates_only_provided_key(): void
    {
        $client = $this->createClient();

        $response = $this->patchJson("/api/v1/clients/{$client->id}", [
            'notification_prefs' => ['whatsapp_reminder' => false],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.notification_prefs.whatsapp_reminder', false)
            ->assertJsonPath('data.notification_prefs.email_new_booking', true);

        $client->refresh();
        $this->assertFalse($client->whatsapp_reminder);
        $this->assertTrue($client->email_new_booking);
        $this->assertTrue($client->email_booking_confirmation);
        $this->assertTrue($client->email_booking_cancellation);
        $this->assertTrue($client->whatsapp_cancellation_confirmation);
    }

    public function test_patch_notifications_enabled_top_level(): void
    {
        $client = $this->createClient();

        $response = $this->patchJson("/api/v1/clients/{$client->id}", [
            'notifications_enabled' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.notifications_enabled', false);

        $client->refresh();
        $this->assertFalse($client->notifications_enabled);
        $this->assertTrue($client->whatsapp_reminder);
    }

    public function test_patch_mixes_regular_field_and_prefs(): void
    {
        $client = $this->createClient();

        $response = $this->patchJson("/api/v1/clients/{$client->id}", [
            'first_name' => 'Maria',
            'notification_prefs' => ['email_booking_cancellation' => false],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.first_name', 'Maria')
            ->assertJsonPath('data.notification_prefs.email_booking_cancellation', false);

        $client->refresh();
        $this->assertSame('Maria', $client->first_name);
        $this->assertFalse($client->email_booking_cancellation);
        $this->assertTrue($client->email_new_booking);
    }

    // ── R3 / SC3 / BR4: Validation ─────────────────────────────────

    public function test_patch_unknown_pref_key_returns_422(): void
    {
        $client = $this->createClient();

        $response = $this->patchJson("/api/v1/clients/{$client->id}", [
            'notification_prefs' => ['unknown_flag' => true],
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'error' => 'validation',
            ])
            ->assertJsonPath('detail', fn (string $detail) => str_contains($detail, 'unknown_flag'));

        $client->refresh();
        $this->assertTrue($client->whatsapp_reminder);
    }

    public function test_patch_non_boolean_pref_returns_422(): void
    {
        $client = $this->createClient();

        $response = $this->patchJson("/api/v1/clients/{$client->id}", [
            'notification_prefs' => ['whatsapp_reminder' => 'yes'],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['notification_prefs.whatsapp_reminder']);

        $client->refresh();
        $this->assertTrue($client->whatsapp_reminder);
    }

    public function test_patch_non_object_prefs_returns_422(): void
    {
        $client = $this->createClient();

        $response = $this->patchJson("/api/v1/clients/{$client->id}", [
            'notification_prefs' => 'not-an-object',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['notification_prefs']);
    }

    // ── Scope boundary: store() does NOT accept prefs ──────────────

    public function test_store_ignores_notification_prefs(): void
    {
        $response = $this->postJson('/api/v1/clients', [
            'first_name' => 'Nuevo',
            'last_name' => 'Cliente',
            'email' => 'nuevo@test.com',
            'notification_prefs' => ['whatsapp_reminder' => false],
        ]);

        $response->assertStatus(201);

        $client = Client::where('email', 'nuevo@test.com')->firstOrFail();
        $this->assertTrue($client->whatsapp_reminder);
        $this->assertTrue($client->notifications_enabled);
    }
}
