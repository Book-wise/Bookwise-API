<?php

namespace Tests\Feature\Api\V1;

use App\Jobs\PushNotificationToCarlitox;
use App\Models\Booking;
use App\Models\BookingStatus;
use App\Models\Client;
use App\Models\Location;
use App\Models\Service;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PushNotificationToCarlitoxTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const WEBHOOK_URL = 'https://carlitox.test/webhook';

    private Client $client;

    private Location $location;

    private Service $service;

    private BookingStatus $status;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.carlitox.webhook_url' => self::WEBHOOK_URL]);

        $this->location = Location::create([
            'name' => 'Test Location',
            'address' => '123 Test St',
        ]);

        $this->service = Service::create([
            'name' => 'Test Service',
            'price' => 50000,
            'duration_minutes' => 60,
        ]);

        $this->status = BookingStatus::create([
            'name' => 'Confirmed',
            'is_cancellation' => false,
        ]);

        $this->client = Client::create([
            'first_name' => 'Juan',
            'last_name' => 'Perez',
            'email' => 'juan@test.com',
            'phone' => '+56912345678',
            'active' => true,
            'notifications_enabled' => true,
        ]);
    }

    private function createBooking(array $overrides = []): Booking
    {
        return Booking::create(array_merge([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'location_id' => $this->location->id,
            'status_id' => $this->status->id,
            'start_time' => Carbon::tomorrow()->addHours(10),
            'end_time' => Carbon::tomorrow()->addHours(11),
            'price' => 50000,
        ], $overrides));
    }

    private function makeJob(Booking $booking): PushNotificationToCarlitox
    {
        return new PushNotificationToCarlitox(
            booking: $booking,
            event: PushNotificationToCarlitox::EVENT_BOOKING_CONFIRMED,
            channel: PushNotificationToCarlitox::CHANNEL_EMAIL,
        );
    }

    // ── SC7: Payload contract ──────────────────────────────────────

    public function test_posts_payload_contract_to_webhook_url(): void
    {
        Http::fake(['*' => Http::response(['received' => true], 200)]);
        Http::preventStrayRequests();

        $booking = $this->createBooking();
        $this->makeJob($booking)->handle();

        Http::assertSent(function ($request) use ($booking) {
            return $request->url() === self::WEBHOOK_URL
                && $request['event'] === PushNotificationToCarlitox::EVENT_BOOKING_CONFIRMED
                && $request['channel'] === PushNotificationToCarlitox::CHANNEL_EMAIL
                && $request['booking']['id'] === $booking->id
                && $request['booking']['start_time'] === $booking->start_time->toIso8601String()
                && $request['booking']['end_time'] === $booking->end_time->toIso8601String()
                && $request['booking']['service'] === $this->service->name
                && $request['booking']['location'] === $this->location->name
                && $request['booking']['status'] === $this->status->name
                && $request['client']['id'] === $this->client->id
                && $request['client']['first_name'] === $this->client->first_name
                && $request['client']['last_name'] === $this->client->last_name
                && $request['client']['email'] === $this->client->email
                && $request['client']['phone'] === $this->client->phone
                && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $request['triggered_at']) === 1;
        });
    }

    // ── BR14: Non-2xx throws (RequestException → job retry path) ──

    public function test_non_2xx_response_throws(): void
    {
        Http::fake(['*' => Http::response('Server Error', 500)]);

        $this->expectException(RequestException::class);

        $this->makeJob($this->createBooking())->handle();
    }

    // ── BR14: Null webhook_url throws loudly ───────────────────────

    public function test_null_webhook_url_throws(): void
    {
        config(['services.carlitox.webhook_url' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('carlitox webhook_url is not configured');

        $this->makeJob($this->createBooking())->handle();
    }

    // ── BR2: Runtime flag gate — toggled off after dispatch ────────

    public function test_skips_when_flag_toggled_off_between_dispatch_and_run(): void
    {
        Http::fake();
        Http::preventStrayRequests();

        $booking = $this->createBooking();

        // Flag flipped after the job was dispatched (defense in depth).
        $this->client->update(['email_booking_confirmation' => false]);

        $this->makeJob($booking)->handle();

        Http::assertNothingSent();
    }

    // ── Client-null guard ───────────────────────────────────────────

    public function test_skips_when_client_missing(): void
    {
        Http::fake();
        Http::preventStrayRequests();

        $booking = $this->createBooking();
        $this->client->delete(); // soft delete — booking->client resolves to null

        $this->makeJob($booking)->handle();

        Http::assertNothingSent();
    }

    // ── failed() logs with context ──────────────────────────────────

    public function test_failed_logs_error_with_context(): void
    {
        Log::spy();

        $booking = $this->createBooking();
        $job = $this->makeJob($booking);

        $job->failed(new RuntimeException('boom'));

        Log::shouldHaveReceived('error')
            ->once()
            ->with('PushNotificationToCarlitox failed', Mockery::on(function (array $context) use ($booking) {
                return $context['booking_id'] === $booking->id
                    && $context['event'] === PushNotificationToCarlitox::EVENT_BOOKING_CONFIRMED
                    && $context['channel'] === PushNotificationToCarlitox::CHANNEL_EMAIL
                    && $context['error'] === 'boom';
            }));
    }

    // ── BR15: Unique identity + queue metadata ──────────────────────

    public function test_unique_id_and_unique_for(): void
    {
        $booking = $this->createBooking();
        $job = $this->makeJob($booking);

        $this->assertSame(
            "booking-{$booking->id}-".PushNotificationToCarlitox::EVENT_BOOKING_CONFIRMED.'-'.PushNotificationToCarlitox::CHANNEL_EMAIL,
            $job->uniqueId()
        );
        $this->assertSame(60, $job->uniqueFor());
    }

    public function test_queue_tries_and_backoff(): void
    {
        $job = $this->makeJob($this->createBooking());

        $this->assertSame('notifications', $job->queue);
        $this->assertSame(5, $job->tries);
        $this->assertSame([3, 10, 30, 60, 120], $job->backoff());
    }
}
