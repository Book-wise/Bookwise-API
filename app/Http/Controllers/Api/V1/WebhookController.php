<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWooCommerceWebhook;
use App\Models\WoocommerceWebhooksLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $secret = config('services.woocommerce.webhook_secret');

        if (! is_string($secret) || trim($secret) === '') {
            return response()->json([
                'error' => 'configuration_error',
                'detail' => 'WooCommerce webhook secret is not configured.',
            ], 503);
        }

        $payload = $request->getContent();
        $signature = $request->header('X-WC-Webhook-Signature');
        $expected = base64_encode(hash_hmac('sha256', $payload, $secret, true));

        if (! hash_equals($expected, $signature ?? '')) {
            Log::warning('WooCommerce webhook signature mismatch', [
                'event' => $request->header('X-WC-Webhook-Topic', 'unknown'),
            ]);

            return response()->json(['error' => 'unauthorized', 'detail' => 'Invalid webhook signature.'], 401);
        }

        $data = json_decode($payload, true);
        if (! is_array($data)) {
            return response()->json(['error' => 'invalid_payload'], 400);
        }

        $event = $request->header('X-WC-Webhook-Topic', 'unknown');
        $isCustomerEvent = str_contains($event, 'customer.');
        $entityId = $data['id'] ?? null;

        $log = WoocommerceWebhooksLog::create([
            'event' => $event,
            'wc_order_id' => $isCustomerEvent ? null : $entityId,
            'wc_entity_id' => $entityId,
            'entity_type' => $isCustomerEvent ? 'customer' : 'order',
            'payload' => $this->sanitizePayload($data, $event),
            'status' => 'received',
        ]);

        ProcessWooCommerceWebhook::dispatch($event, $payload, $log->id);

        return response()->json(['received' => true], 200);
    }

    /**
     * Retain delivery evidence without persisting customer billing or shipping data.
     *
     * @return array<string, mixed>
     */
    private function sanitizePayload(array $data, string $event): array
    {
        $allowedMetaKeys = [
            '_kinesilk_slot_start',
            '_kinesilk_slot_end',
            '_kinesilk_location_id',
            '_kinesilk_service_id',
            '_kinesilk_duration_minutes',
        ];

        $lineItems = collect($data['line_items'] ?? [])
            ->map(function (array $item) use ($allowedMetaKeys): array {
                return array_filter([
                    'id' => $item['id'] ?? null,
                    'product_id' => $item['product_id'] ?? null,
                    'variation_id' => $item['variation_id'] ?? null,
                    'meta_data' => collect($item['meta_data'] ?? [])
                        ->filter(fn (array $meta): bool => in_array($meta['key'] ?? null, $allowedMetaKeys, true))
                        ->map(fn (array $meta): array => [
                            'key' => $meta['key'],
                            'value' => $meta['value'] ?? null,
                        ])
                        ->values()
                        ->all(),
                ], fn ($value): bool => $value !== null);
            })
            ->values()
            ->all();

        return array_filter([
            'topic' => $event,
            'id' => $data['id'] ?? null,
            'parent_id' => $data['parent_id'] ?? null,
            'customer_id' => $data['customer_id'] ?? null,
            'status' => $data['status'] ?? null,
            'currency' => $data['currency'] ?? null,
            'total' => $data['total'] ?? null,
            'date_created' => $data['date_created'] ?? null,
            'date_modified' => $data['date_modified'] ?? null,
            'date_paid' => $data['date_paid'] ?? null,
            'date_completed' => $data['date_completed'] ?? null,
            'line_items' => $lineItems,
        ], fn ($value): bool => $value !== null && $value !== []);
    }
}
