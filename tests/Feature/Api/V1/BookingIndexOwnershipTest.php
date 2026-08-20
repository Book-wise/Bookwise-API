<?php

namespace Tests\Feature\Api\V1;

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

class BookingIndexOwnershipTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Location $locationA;

    private Location $locationB;

    private Provider $providerA;

    private Provider $providerB;

    private Service $service;

    private Client $client;

    private BookingStatus $status;

    private User $providerUserA;

    private User $providerUserB;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->locationA = Location::create([
            'name' => 'Location A',
            'address' => '1 A St',
        ]);

        $this->locationB = Location::create([
            'name' => 'Location B',
            'address' => '2 B St',
        ]);

        $this->providerA = Provider::create([
            'first_name' => 'Provider',
            'last_name' => 'A',
            'email' => 'provider-a@test.com',
            'location_id' => $this->locationA->id,
            'active' => true,
        ]);

        $this->providerB = Provider::create([
            'first_name' => 'Provider',
            'last_name' => 'B',
            'email' => 'provider-b@test.com',
            'location_id' => $this->locationB->id,
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

        $this->providerUserA = User::factory()->create([
            'role' => UserRole::PROVIDER,
            'provider_id' => $this->providerA->id,
        ]);

        $this->providerUserB = User::factory()->create([
            'role' => UserRole::PROVIDER,
            'provider_id' => $this->providerB->id,
        ]);

        $this->admin = User::factory()->create([
            'role' => UserRole::ADMIN,
        ]);
    }

    private function createBooking(Location $location): Booking
    {
        return Booking::create([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'provider_id' => $location->id === $this->locationA->id ? $this->providerA->id : $this->providerB->id,
            'location_id' => $location->id,
            'status_id' => $this->status->id,
            'start_time' => Carbon::tomorrow()->addHours(10),
            'end_time' => Carbon::tomorrow()->addHours(11),
            'price' => 50000,
        ]);
    }

    private function authenticateAs(User $user, array $abilities = ['*']): void
    {
        $token = $user->createToken('test-token', $abilities);
        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken);
    }

    public function test_provider_sees_only_their_locations_bookings(): void
    {
        $bookingA = $this->createBooking($this->locationA);
        $bookingB = $this->createBooking($this->locationB);

        $this->authenticateAs($this->providerUserA, ['bookings:read']);

        $response = $this->getJson('/api/v1/bookings');

        $response->assertStatus(200);
        $ids = collect($response->json())->pluck('id')->all();
        $this->assertContains($bookingA->id, $ids);
        $this->assertNotContains($bookingB->id, $ids);
    }

    public function test_provider_without_profile_gets_forbidden(): void
    {
        $orphanUser = User::factory()->create([
            'role' => UserRole::PROVIDER,
            'provider_id' => null,
        ]);

        $this->authenticateAs($orphanUser, ['bookings:read']);

        $response = $this->getJson('/api/v1/bookings');

        $response->assertStatus(403);
    }

    public function test_admin_sees_all_bookings(): void
    {
        $bookingA = $this->createBooking($this->locationA);
        $bookingB = $this->createBooking($this->locationB);

        $this->authenticateAs($this->admin);

        $response = $this->getJson('/api/v1/bookings');

        $response->assertStatus(200);
        $ids = collect($response->json())->pluck('id')->all();
        $this->assertContains($bookingA->id, $ids);
        $this->assertContains($bookingB->id, $ids);
    }
}
