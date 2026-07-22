<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\BlockedSlot;
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

class BookingAuthorizationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Location $providerLocation;

    private Location $otherLocation;

    private Service $service;

    private Client $client;

    private BookingStatus $status;

    private User $providerUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->providerLocation = Location::create(['name' => 'Provider location', 'address' => 'One']);
        $this->otherLocation = Location::create(['name' => 'Other location', 'address' => 'Two']);
        $this->service = Service::create(['name' => 'Service', 'price' => 10000, 'duration_minutes' => 60]);
        $this->client = Client::create(['first_name' => 'Client', 'last_name' => 'Test', 'email' => 'client@example.test']);
        $this->status = BookingStatus::create(['name' => 'Confirmed', 'is_cancellation' => false]);
        $provider = Provider::create([
            'first_name' => 'Provider',
            'last_name' => 'Test',
            'email' => 'provider@example.test',
            'location_id' => $this->providerLocation->id,
        ]);
        $this->providerUser = User::factory()->create([
            'role' => UserRole::PROVIDER,
            'provider_id' => $provider->id,
        ]);
    }

    public function test_provider_cannot_view_or_update_booking_outside_its_location(): void
    {
        $booking = $this->bookingFor($this->otherLocation);
        $this->authenticate($this->providerUser, ['bookings:read', 'bookings:write']);

        $this->getJson("/api/v1/bookings/{$booking->id}")->assertStatus(403);
        $this->patchJson("/api/v1/bookings/{$booking->id}", ['notes' => 'Not allowed'])->assertStatus(403);
        $this->getJson('/api/v1/bookings')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_provider_cannot_access_another_provider_booking_at_the_same_location(): void
    {
        $otherProvider = Provider::create([
            'first_name' => 'Other',
            'last_name' => 'Provider',
            'email' => 'other-provider@example.test',
            'location_id' => $this->providerLocation->id,
        ]);
        $booking = $this->bookingFor($this->providerLocation);
        $booking->update(['provider_id' => $otherProvider->id]);

        $this->authenticate($this->providerUser, ['bookings:read', 'bookings:write']);

        $this->getJson("/api/v1/bookings/{$booking->id}")->assertStatus(403);
        $this->patchJson("/api/v1/bookings/{$booking->id}", ['notes' => 'Not allowed'])->assertStatus(403);
        $this->getJson('/api/v1/bookings')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_provider_cannot_create_a_booking_outside_its_location(): void
    {
        $this->authenticate($this->providerUser, ['bookings:write']);
        $start = Carbon::tomorrow()->setTime(10, 0);

        $this->postJson('/api/v1/bookings', [
            'start_time' => $start->toIso8601String(),
            'end_time' => $start->copy()->addHour()->toIso8601String(),
            'service_id' => $this->service->id,
            'client_id' => $this->client->id,
            'location_id' => $this->otherLocation->id,
            'status_id' => $this->status->id,
        ])->assertStatus(403);
    }

    public function test_admin_can_view_any_booking_and_create_a_service_with_resource_envelope(): void
    {
        $booking = $this->bookingFor($this->otherLocation);
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $this->authenticate($admin, ['*']);

        $this->getJson("/api/v1/bookings/{$booking->id}")->assertOk()->assertJsonPath('data.id', $booking->id);
        $this->postJson('/api/v1/services', [
            'name' => 'Administrative service',
            'price' => 15000,
            'duration_minutes' => 45,
        ])->assertCreated()->assertJsonPath('data.name', 'Administrative service');
    }

    public function test_booking_and_available_slots_reject_agenda_blocks(): void
    {
        $start = Carbon::tomorrow()->setTime(10, 0);
        $end = $start->copy()->addHour();
        BlockedSlot::create([
            'location_id' => $this->providerLocation->id,
            'start_time' => $start,
            'end_time' => $end,
            'reason' => 'Unavailable',
        ]);

        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $this->authenticate($admin, ['*']);
        $this->postJson('/api/v1/bookings', [
            'start_time' => $start->toIso8601String(),
            'end_time' => $end->toIso8601String(),
            'service_id' => $this->service->id,
            'client_id' => $this->client->id,
            'location_id' => $this->providerLocation->id,
            'status_id' => $this->status->id,
        ])->assertStatus(409)->assertJsonPath('conflicts_with.type', 'blocked_slot');

        $this->getJson('/api/v1/available_slots?location_id='.$this->providerLocation->id.'&start_date='.$start->toDateString().'&duration_minutes=60&slot_interval=60')
            ->assertOk()
            ->assertJsonMissing(['start' => $start->toIso8601String()]);
    }

    /** @param array<int, string> $abilities */
    private function authenticate(User $user, array $abilities): void
    {
        $token = $user->createToken('test-token', $abilities);
        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken);
    }

    private function bookingFor(Location $location): Booking
    {
        return Booking::create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'location_id' => $location->id,
            'status_id' => $this->status->id,
            'start_time' => Carbon::tomorrow()->setTime(13, 0),
            'end_time' => Carbon::tomorrow()->setTime(14, 0),
            'price' => 10000,
        ]);
    }
}
