<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingStatus;
use App\Models\Sale;
use App\Models\WoocommerceWebhooksLog;
use App\Services\BookingService;
use App\Services\ClientService;
use App\Services\SaleService;
use App\Services\WooCommerceCustomerService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WebhookController extends Controller
{
    public function __construct(
        private WooCommerceCustomerService $customerService,
        private ClientService $clientService,
        private BookingService $bookingService,
        private SaleService $saleService,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $secret = config('services.woocommerce.webhook_secret');
        $payload = $request->getContent();
        $signature = $request->header('X-WC-Webhook-Signature');
        $expected = base64_encode(hash_hmac('sha256', $payload, $secret, true));

        if (! hash_equals($expected, $signature ?? '')) {
            return response()->json(['error' => 'unauthorized', 'detail' => 'Invalid webhook signature.'], 401);
        }

        $data = json_decode($payload, true);
        $event = $request->header('X-WC-Webhook-Topic', 'unknown');

        // Check for customer events first
        if (str_contains($event, 'customer.created') || str_contains($event, 'customer.updated')) {
            return $this->handleCustomerEvent($data, $event);
        }

        // Order events
        $orderId = $data['id'] ?? null;

        $log = WoocommerceWebhooksLog::create([
            'event' => $event,
            'wc_order_id' => $orderId,
            'wc_entity_id' => $orderId,
            'entity_type' => 'order',
            'payload' => $payload,
            'status' => 'received',
        ]);

        try {
            if (str_contains($event, 'order.completed')) {
                return $this->handleOrderCompleted($data, $log);
            } elseif (str_contains($event, 'order.refunded')) {
                $this->handleOrderRefunded($data, $log);
            } else {
                $log->update(['status' => 'processed']);
            }
        } catch (\Throwable $e) {
            $log->update(['status' => 'failed', 'error_message' => $e->getMessage()]);

            return response()->json(['error' => 'processing_failed'], 500);
        }

        return response()->json(['received' => true], 200);
    }

    private function handleCustomerEvent(array $data, string $event): JsonResponse
    {
        $customerId = $data['id'] ?? null;

        $log = WoocommerceWebhooksLog::create([
            'event' => $event,
            'wc_entity_id' => $customerId,
            'entity_type' => 'customer',
            'payload' => json_encode($data),
            'status' => 'received',
        ]);

        try {
            $this->customerService->syncCustomer($data, $event);
            $log->update(['status' => 'processed']);
        } catch (\Throwable $e) {
            $log->update(['status' => 'failed', 'error_message' => $e->getMessage()]);

            return response()->json(['error' => 'processing_failed'], 500);
        }

        return response()->json(['received' => true], 200);
    }

    private function handleOrderCompleted(array $data, WoocommerceWebhooksLog $log): JsonResponse
    {
        $orderId = $data['id'] ?? null;

        // Step 1: Extract line-item meta
        $meta = $this->extractLineItemMeta($data);

        if ($meta === null) {
            $log->update(['status' => 'processed']);

            return response()->json(['received' => true], 200);
        }

        // Step 2: Extract billing data
        $billing = $this->extractBillingData($data);

        if ($billing === null) {
            $log->update(['status' => 'failed', 'error_message' => 'Missing billing.email']);

            return response()->json(['error' => 'validation_error', 'detail' => 'Missing billing.email in payload.'], 400);
        }

        try {
            // Step 3: Sync / create client
            $client = $this->clientService->syncFromWooCommerce($billing);

            // Step 4: Check idempotency — existing booking with this wc_order_id
            $existingBooking = Booking::where('wc_order_id', $orderId)->first();

            if ($existingBooking) {
                $existingSale = Sale::where('wc_order_id', $orderId)->first();

                $log->update(['status' => 'processed']);

                return response()->json([
                    'booking_id' => $existingBooking->id,
                    'sale_id' => $existingSale?->id,
                    'client_id' => $client->id,
                ], 200);
            }

            // Step 5: Verify slot availability
            $available = $this->bookingService->verifyAvailability(
                $meta['location_id'],
                $meta['slot_start'],
                $meta['slot_end'],
            );

            if (! $available) {
                $log->update([
                    'status' => 'failed',
                    'error_message' => 'Slot unavailable for location '.$meta['location_id']
                        .' from '.$meta['slot_start'].' to '.$meta['slot_end'],
                ]);

                return response()->json([
                    'error' => 'slot_unavailable',
                    'detail' => 'The requested time slot is no longer available.',
                ], 409);
            }

            // Steps 6-7: Create booking, sale, and transaction atomically
            $confirmedStatus = BookingStatus::where('is_cancellation', false)->first();

            if ($confirmedStatus === null) {
                $log->update([
                    'status' => 'failed',
                    'error_message' => 'No non-cancellation booking status configured',
                ]);

                return response()->json(['error' => 'configuration_error'], 500);
            }

            $paidAt = isset($data['date_paid'])
                ? Carbon::parse($data['date_paid'])
                : now();

            $result = DB::transaction(function () use ($data, $orderId, $client, $meta, $confirmedStatus, $log, $paidAt) {
                $booking = $this->bookingService->findOrCreateBooking([
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
                ]);

                // Add status history for newly created booking
                $booking->statusHistory()->create([
                    'status_id' => $confirmedStatus->id,
                    'notes' => 'Confirmed via WooCommerce order #'.$orderId,
                ]);

                // Step 7: Create sale and transaction
                $sale = $this->saleService->createFromBooking($booking, [
                    'wc_order_id' => $orderId,
                    'total' => $data['total'] ?? $booking->price,
                    'payment_method' => $data['payment_method'] ?? 'online',
                    'paid_at' => $paidAt,
                ]);

                $log->update(['status' => 'processed']);

                return [
                    'booking_id' => $booking->id,
                    'sale_id' => $sale->id,
                    'client_id' => $client->id,
                ];
            });

            return response()->json($result, 200);
        } catch (\Throwable $e) {
            $log->update(['status' => 'failed', 'error_message' => $e->getMessage()]);

            return response()->json(['error' => 'processing_failed'], 500);
        }
    }

    private function handleOrderRefunded(array $data, $log): void
    {
        $orderId = $data['id'] ?? null;
        $booking = Booking::where('wc_order_id', $orderId)->first();

        if (! $booking) {
            $log->update(['status' => 'processed']);

            return;
        }

        $cancelStatus = BookingStatus::where('is_cancellation', true)->first();
        if ($cancelStatus) {
            $booking->update(['status_id' => $cancelStatus->id]);
            $booking->statusHistory()->create([
                'status_id' => $cancelStatus->id,
                'notes' => 'Cancelled via WooCommerce refund order #'.$orderId,
            ]);
        }

        $log->update(['status' => 'processed']);
    }

    /**
     * Extract line-item meta data from the webhook payload.
     * Uses only the first line item's meta_data.
     *
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
