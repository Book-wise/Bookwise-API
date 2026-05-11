<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingStatus;
use App\Models\Sale;
use App\Models\WoocommerceWebhooksLog;
use App\Services\WooCommerceCustomerService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class WebhookController extends Controller
{
    public function __construct(
        private WooCommerceCustomerService $customerService
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $secret    = config('services.woocommerce.webhook_secret');
        $payload   = $request->getContent();
        $signature = $request->header('X-WC-Webhook-Signature');
        $expected  = base64_encode(hash_hmac('sha256', $payload, $secret, true));

        if (!hash_equals($expected, $signature ?? '')) {
            return response()->json(['error' => 'unauthorized', 'detail' => 'Invalid webhook signature.'], 401);
        }

        $data    = json_decode($payload, true);
        $event   = $request->header('X-WC-Webhook-Topic', 'unknown');

        // Check for customer events first
        if (str_contains($event, 'customer.created') || str_contains($event, 'customer.updated')) {
            return $this->handleCustomerEvent($data, $event);
        }

        // Order events (existing logic)
        $orderId = $data['id'] ?? null;

        $log = WoocommerceWebhooksLog::create([
            'event'        => $event,
            'wc_order_id'  => $orderId,
            'wc_entity_id' => $orderId,
            'entity_type'  => 'order',
            'payload'      => $payload,
            'status'       => 'received',
        ]);

        try {
            if (str_contains($event, 'order.completed')) {
                $this->handleOrderCompleted($data, $log);
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

        // Log the customer event
        $log = WoocommerceWebhooksLog::create([
            'event'         => $event,
            'wc_entity_id'  => $customerId,
            'entity_type'   => 'customer',
            'payload'       => json_encode($data),
            'status'        => 'received',
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

    private function handleOrderCompleted(array $data, $log): void
    {
        $orderId  = $data['id'] ?? null;
        $meta     = collect($data['meta_data'] ?? []);

        $bookingId       = $meta->firstWhere('key', '_kinesilk_booking_id')['value']      ?? null;
        $durationMinutes = $meta->firstWhere('key', '_kinesilk_duration_minutes')['value'] ?? null;

        $booking = $bookingId
            ? Booking::find($bookingId)
            : Booking::where('wc_order_id', $orderId)->first();

        if (!$booking) {
            $log->update(['status' => 'processed']);
            return;
        }

        if ($durationMinutes) {
            $booking->update([
                'custom_duration_minutes' => (int) $durationMinutes,
                'end_time'                => $booking->start_time->copy()->addMinutes((int) $durationMinutes),
            ]);
        }

        $confirmed = BookingStatus::where('is_cancellation', false)->first();
        if ($confirmed) {
            $booking->update(['status_id' => $confirmed->id, 'wc_order_id' => $orderId]);
            $booking->statusHistory()->create([
                'status_id' => $confirmed->id,
                'notes'     => 'Confirmed via WooCommerce order #' . $orderId,
            ]);
        }

        Sale::firstOrCreate(
            ['wc_order_id' => $orderId],
            [
                'booking_id'     => $booking->id,
                'total'          => $data['total'] ?? $booking->price,
                'payment_method' => $data['payment_method'] ?? null,
                'paid_at'        => now(),
            ]
        );

        $log->update(['status' => 'processed']);
    }

    private function handleOrderRefunded(array $data, $log): void
    {
        $orderId = $data['id'] ?? null;
        $booking = Booking::where('wc_order_id', $orderId)->first();

        if (!$booking) {
            $log->update(['status' => 'processed']);
            return;
        }

        $cancelStatus = BookingStatus::where('is_cancellation', true)->first();
        if ($cancelStatus) {
            $booking->update(['status_id' => $cancelStatus->id]);
            $booking->statusHistory()->create([
                'status_id' => $cancelStatus->id,
                'notes'     => 'Cancelled via WooCommerce refund order #' . $orderId,
            ]);
        }

        $log->update(['status' => 'processed']);
    }
}
