<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Models\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PushNotificationToCarlitox implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const EVENT_BOOKING_CREATED = 'booking.created';

    public const EVENT_BOOKING_CONFIRMED = 'booking.confirmed';

    public const EVENT_BOOKING_CANCELLED = 'booking.cancelled';

    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_WHATSAPP = 'whatsapp';

    /**
     * Maximum number of retry attempts before marking as failed.
     */
    public int $tries = 5;

    public function __construct(
        public Booking $booking,
        public string $event,
        public string $channel,
    ) {
        $this->onQueue('notifications');
    }

    /**
     * Execute the job — re-gate flags at run time, then POST the payload.
     */
    public function handle(): void
    {
        $this->booking->loadMissing(['client', 'service', 'location', 'status']);

        $client = $this->booking->client;

        if (! $client || ! $client->notifications_enabled) {
            return;
        }

        $flag = $this->flagFor($this->event, $this->channel);

        if ($flag !== null && ! $client->{$flag}) {
            return;
        }

        $url = config('services.carlitox.webhook_url');

        if (blank($url)) {
            throw new RuntimeException('carlitox webhook_url is not configured');
        }

        $response = Http::timeout(10)
            ->connectTimeout(5)
            ->post($url, $this->payload($client));

        $response->throw();
    }

    /**
     * Get the retry backoff times in seconds.
     *
     * @return int[]
     */
    public function backoff(): array
    {
        return [3, 10, 30, 60, 120];
    }

    /**
     * Get the unique identifier for the job, preventing duplicate pushes.
     */
    public function uniqueId(): string
    {
        return "booking-{$this->booking->id}-{$this->event}-{$this->channel}";
    }

    /**
     * The duration (in seconds) during which the job's uniqueness lock is held.
     */
    public function uniqueFor(): int
    {
        return 60;
    }

    /**
     * Handle a job failure — log with full context for ops visibility.
     */
    public function failed(\Throwable $e): void
    {
        Log::error('PushNotificationToCarlitox failed', [
            'booking_id' => $this->booking->id,
            'event' => $this->event,
            'channel' => $this->channel,
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * Resolve the per-client flag column for an event/channel combination.
     * Combinations without a matrix entry are never dispatched by the listener.
     */
    private function flagFor(string $event, string $channel): ?string
    {
        return match (true) {
            $event === self::EVENT_BOOKING_CREATED && $channel === self::CHANNEL_EMAIL => 'email_new_booking',
            $event === self::EVENT_BOOKING_CONFIRMED && $channel === self::CHANNEL_EMAIL => 'email_booking_confirmation',
            $event === self::EVENT_BOOKING_CANCELLED && $channel === self::CHANNEL_EMAIL => 'email_booking_cancellation',
            $event === self::EVENT_BOOKING_CANCELLED && $channel === self::CHANNEL_WHATSAPP => 'whatsapp_cancellation_confirmation',
            default => null,
        };
    }

    /**
     * Build the carlitox payload contract (SC7).
     *
     * @return array{
     *     event: string,
     *     channel: string,
     *     booking: array{id: int, start_time: string|null, end_time: string|null, service: string|null, location: string|null, status: string|null},
     *     client: array{id: int, first_name: string, last_name: string, email: string, phone: string|null},
     *     triggered_at: string
     * }
     */
    private function payload(Client $client): array
    {
        return [
            'event' => $this->event,
            'channel' => $this->channel,
            'booking' => [
                'id' => $this->booking->id,
                'start_time' => $this->booking->start_time?->toIso8601String(),
                'end_time' => $this->booking->end_time?->toIso8601String(),
                'service' => $this->booking->service?->name,
                'location' => $this->booking->location?->name,
                'status' => $this->booking->status?->name,
            ],
            'client' => [
                'id' => $client->id,
                'first_name' => $client->first_name,
                'last_name' => $client->last_name,
                'email' => $client->email,
                'phone' => $client->phone,
            ],
            'triggered_at' => now()->toIso8601String(),
        ];
    }
}
