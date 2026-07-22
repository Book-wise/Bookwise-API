<?php

namespace App\Jobs;

use App\Enums\BookingSource;
use App\Models\Booking;
use App\Models\BookingStatus;
use App\Models\WoocommerceWebhooksLog;
use App\Services\BookingService;
use App\Services\ClientService;
use App\Services\SaleService;
use App\Services\SchedulingLockService;
use App\Services\WooCommerceCustomerService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProcessWooCommerceWebhook implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The webhook event topic (e.g., "order.updated", "customer.created").
     */
    public string $event;

    /**
     * The raw JSON payload string from the webhook request body.
     */
    public string $payload;

    /**
     * The WoocommerceWebhooksLog record ID for tracking.
     */
    public int $logId;

    /**
     * Maximum number of retry attempts before marking as failed.
     */
    public $tries = 5;

    public function __construct(string $event, string $payload, int $logId)
    {
        $this->event = $event;
        $this->payload = $payload;
        $this->logId = $logId;
        $this->onQueue('webhooks');
    }

    /**
     * Execute the job — route the event to the appropriate handler.
     */
    public function handle(
        WooCommerceCustomerService $customerService,
        ClientService $clientService,
        BookingService $bookingService,
        SaleService $saleService,
    ): void {
        $log = WoocommerceWebhooksLog::findOrFail($this->logId);
        $log->update(['status' => 'processing']);

        $data = json_decode($this->payload, true);

        try {
            if (! is_array($data)) {
                throw new RuntimeException('Webhook payload is not valid JSON.');
            }

            $schedulingLocks = app(SchedulingLockService::class);

            if (str_contains($this->event, 'customer.')) {
                $this->handleCustomerEvent($data, $customerService);
            } elseif (str_contains($this->event, 'order.')) {
                $status = $data['status'] ?? '';

                match ($status) {
                    'completed' => $this->handleOrderCompleted($data, $clientService, $bookingService, $saleService, $schedulingLocks),
                    'refunded' => $this->handleOrderRefunded($data, $schedulingLocks),
                    default => null, // Other order status changes are ignored
                };
            }

            $log->update(['status' => 'processed']);
        } catch (\Throwable $e) {
            $log->update(['status' => 'failed', 'error_message' => 'Webhook processing failed: '.class_basename($e)]);

            throw $e;
        }
    }

    /**
     * Handle a job failure — update the log to failed with the error message.
     */
    public function failed(\Throwable $e): void
    {
        $log = WoocommerceWebhooksLog::find($this->logId);

        if ($log) {
            $log->update(['status' => 'failed', 'error_message' => 'Webhook processing failed: '.class_basename($e)]);
        }
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
     * Get the unique identifier for the job, preventing duplicate processing.
     */
    public function uniqueId(): string
    {
        $data = json_decode($this->payload, true);

        if (str_contains($this->event, 'customer.')) {
            return $this->event.'-customer-'.($data['id'] ?? 'unknown');
        }

        return $this->event.'-order-'.($data['id'] ?? 'unknown');
    }

    /**
     * The duration (in seconds) during which the job's uniqueness lock is held.
     */
    public function uniqueFor(): int
    {
        return 60;
    }

    /**
     * Handle a customer created/updated event — sync the customer data.
     */
    private function handleCustomerEvent(array $data, WooCommerceCustomerService $customerService): void
    {
        $customerService->syncCustomer($data, $this->event);
    }

    /**
     * Handle an order.completed event — create booking and sale from line-item meta.
     */
    private function handleOrderCompleted(
        array $data,
        ClientService $clientService,
        BookingService $bookingService,
        SaleService $saleService,
        SchedulingLockService $schedulingLocks,
    ): void {
        $orderId = $data['id'] ?? null;

        // Step 1: Extract line-item meta
        $meta = $this->extractLineItemMeta($data);

        if ($meta === null) {
            return;
        }

        // Step 2: Extract billing data
        $billing = $this->extractBillingData($data);

        if ($billing === null) {
            throw new RuntimeException('Missing billing.email in payload.');
        }

        // Step 3: Sync / create client
        $client = $clientService->syncFromWooCommerce($billing);

        // Step 4: Ensure a confirmed (non-cancellation) status exists
        $confirmedStatus = BookingStatus::where('is_cancellation', false)->first();

        if ($confirmedStatus === null) {
            throw new RuntimeException('No non-cancellation booking status configured');
        }

        $paidAt = isset($data['date_paid'])
            ? Carbon::parse($data['date_paid'])
            : now();

        // Step 5: Lock the affected agenda before idempotency and availability checks.
        $result = DB::transaction(function () use ($data, $orderId, $client, $meta, $confirmedStatus, $paidAt, $bookingService, $saleService, $schedulingLocks): array {
            $schedulingLocks->lock($meta['location_id'], $client->id);

            $existingBooking = Booking::where('wc_order_id', $orderId)->lockForUpdate()->first();
            if ($existingBooking) {
                return ['status' => 'existing'];
            }

            if (! $bookingService->verifyAvailability(
                $meta['location_id'],
                $meta['slot_start'],
                $meta['slot_end'],
            )) {
                return ['status' => 'slot_unavailable'];
            }

            $booking = $bookingService->findOrCreateBooking(
                [
                    'wc_order_id' => $orderId,
                    'client_id' => $client->id,
                    'service_id' => $meta['service_id'],
                    'location_id' => $meta['location_id'],
                    'status_id' => $confirmedStatus->id,
                    'start_time' => $meta['slot_start'],
                    'end_time' => $meta['slot_end'],
                    'custom_duration_minutes' => $meta['duration_minutes'],
                    'price' => $data['total'] ?? 0,
                    'notes' => 'Created via WooCommerce order #'.$orderId,
                ],
                BookingSource::OnlineWebhook
            );

            $booking->statusHistory()->create([
                'status_id' => $confirmedStatus->id,
                'notes' => 'Confirmed via WooCommerce order #'.$orderId,
            ]);

            $saleService->createFromBooking($booking, [
                'wc_order_id' => $orderId,
                'total' => $data['total'] ?? $booking->price,
                'payment_method' => $data['payment_method'] ?? 'online',
                'paid_at' => $paidAt,
            ]);

            return ['status' => 'created'];
        });

        if ($result['status'] === 'slot_unavailable') {
            throw new RuntimeException('Slot unavailable for location '.$meta['location_id']);
        }
    }

    /**
     * Handle an order.refunded event — cancel the associated booking if it exists.
     */
    private function handleOrderRefunded(array $data, SchedulingLockService $schedulingLocks): void
    {
        $orderId = $data['id'] ?? null;

        DB::transaction(function () use ($orderId, $schedulingLocks): void {
            $booking = Booking::where('wc_order_id', $orderId)->lockForUpdate()->first();
            if (! $booking) {
                return;
            }

            $schedulingLocks->lock($booking->location_id, $booking->client_id, $booking->provider_id);
            $cancelStatus = BookingStatus::where('is_cancellation', true)->first();

            if ($cancelStatus && $booking->status_id !== $cancelStatus->id) {
                $booking->update([
                    'status_id' => $cancelStatus->id,
                    'last_modified_via' => BookingSource::OnlineWebhook,
                ]);

                $booking->statusHistory()->create([
                    'status_id' => $cancelStatus->id,
                    'notes' => 'Cancelled via WooCommerce refund order #'.$orderId,
                ]);
            }
        }, 3);
    }

    /**
     * Extract line-item meta data from the webhook payload.
     * Uses only the first line item's meta_data.
     *
     * @param  array  $data  Decoded webhook payload
     * @return array{slot_start: string, slot_end: string, location_id: int, service_id: int, duration_minutes: int}|null
     */
    private function extractLineItemMeta(array $data): ?array
    {
        $lineItems = $data['line_items'] ?? [];

        if (empty($lineItems)) {
            return null;
        }

        $metaData = $lineItems[0]['meta_data'] ?? [];

        if (empty($metaData)) {
            return null;
        }

        $meta = collect($metaData)->pluck('value', 'key');

        $slotStart = $meta->get('_kinesilk_slot_start');
        $slotEnd = $meta->get('_kinesilk_slot_end');
        $locationId = $meta->get('_kinesilk_location_id');
        $serviceId = $meta->get('_kinesilk_service_id');
        $durationMinutes = $meta->get('_kinesilk_duration_minutes');

        if ($slotStart === null || $slotEnd === null || $locationId === null || $serviceId === null) {
            return null;
        }

        return [
            'slot_start' => $slotStart,
            'slot_end' => $slotEnd,
            'location_id' => (int) $locationId,
            'service_id' => (int) $serviceId,
            'duration_minutes' => $durationMinutes !== null ? (int) $durationMinutes : null,
        ];
    }

    /**
     * Extract billing data from the webhook payload.
     *
     * @param  array  $data  Decoded webhook payload
     * @return array{email: string, first_name: string, last_name: string, phone: string|null}|null
     */
    private function extractBillingData(array $data): ?array
    {
        $billing = $data['billing'] ?? [];

        $email = $billing['email'] ?? null;

        if ($email === null || $email === '') {
            return null;
        }

        return [
            'email' => $email,
            'first_name' => $billing['first_name'] ?? '',
            'last_name' => $billing['last_name'] ?? '',
            'phone' => $billing['phone'] ?? null,
        ];
    }
}
