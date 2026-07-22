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
        $payload = $request->getContent();

        // WooCommerce delivery-test ping — no signature, just a connectivity check
        if (str_starts_with($payload, 'webhook_id=')) {
            return response()->json(['received' => true], 200);
        }

        $secret = trim(config('services.woocommerce.webhook_secret'));
        $signature = $request->header('X-WC-Webhook-Signature');
        $expected = base64_encode(hash_hmac('sha256', $payload, $secret, true));

        if (! hash_equals($expected, $signature ?? '')) {
            Log::info('WooCommerce webhook signature mismatch', [
                'header_signature' => $signature,
                'computed_signature' => $expected,
                'raw_body_prefix' => mb_substr($payload, 0, 200),
            ]);

            return response()->json(['error' => 'unauthorized', 'detail' => 'Invalid webhook signature.'], 401);
        }

        $data = json_decode($payload, true);
        $event = $request->header('X-WC-Webhook-Topic', 'unknown');

        // Extraer datos del cliente y la reserva desde el payload
        $billing = $data['billing'] ?? [];
        $lineItemMeta = $data['line_items'][0]['meta_data'] ?? [];
        $meta = collect($lineItemMeta)->pluck('value', 'key');

        Log::info('WooCommerce webhook received', [
            'event' => $event,
            'order_id' => $data['id'] ?? null,
            'status' => $data['status'] ?? null,
            'total' => $data['total'] ?? null,
            'payment_method' => $data['payment_method'] ?? null,
            'date_paid' => $data['date_paid'] ?? null,
            'cliente' => [
                'nombre' => trim(($billing['first_name'] ?? '').' '.($billing['last_name'] ?? '')),
                'email' => $billing['email'] ?? null,
                'telefono' => $billing['phone'] ?? null,
            ],
            'reserva' => [
                'slot_start' => $meta->get('_kinesilk_slot_start'),
                'slot_end' => $meta->get('_kinesilk_slot_end'),
                'location_id' => $meta->get('_kinesilk_location_id'),
                'service_id' => $meta->get('_kinesilk_service_id'),
                'duracion_minutos' => $meta->get('_kinesilk_duration_minutes'),
            ],
        ]);

        // Determine entity type and IDs for logging
        if (str_contains($event, 'customer.')) {
            $entityType = 'customer';
            $entityId = $data['id'] ?? null;
            $orderId = null;
        } else {
            $entityType = 'order';
            $entityId = $data['id'] ?? null;
            $orderId = $data['id'] ?? null;
        }

        $log = WoocommerceWebhooksLog::create([
            'event' => $event,
            'wc_order_id' => $orderId,
            'wc_entity_id' => $entityId,
            'entity_type' => $entityType,
            'payload' => $payload,
            'status' => 'received',
        ]);

        ProcessWooCommerceWebhook::dispatch($event, $payload, $log->id);

        return response()->json(['received' => true], 200);
    }
}
