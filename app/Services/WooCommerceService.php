<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Response;

class WooCommerceService
{
    private string $baseUrl;
    private string $consumerKey;
    private string $consumerSecret;

    public function __construct()
    {
        $this->baseUrl        = rtrim(env('WC_BASE_URL'), '/') . '/wp-json/wc/v3';
        $this->consumerKey    = env('WC_CONSUMER_KEY');
        $this->consumerSecret = env('WC_CONSUMER_SECRET');
    }

    private function client()
    {
        return Http::withBasicAuth($this->consumerKey, $this->consumerSecret)
            ->timeout(15)
            ->acceptJson();
    }

    public function getProduct(int $productId): array
    {
        $response = $this->client()->get("{$this->baseUrl}/products/{$productId}");
        return $response->successful() ? $response->json() : [];
    }

    public function checkStock(int $productId): bool
    {
        $product = $this->getProduct($productId);
        if (empty($product)) return false;
        if ($product['stock_status'] === 'instock') return true;
        if (isset($product['stock_quantity']) && $product['stock_quantity'] > 0) return true;
        return false;
    }

    public function decrementStock(int $productId): bool
    {
        $product = $this->getProduct($productId);
        if (empty($product)) return false;

        $current = $product['stock_quantity'] ?? 0;
        if ($current <= 0) return false;

        $response = $this->client()->put("{$this->baseUrl}/products/{$productId}", [
            'stock_quantity' => $current - 1,
        ]);

        return $response->successful();
    }

    public function getOrder(int $orderId): array
    {
        $response = $this->client()->get("{$this->baseUrl}/orders/{$orderId}");
        return $response->successful() ? $response->json() : [];
    }

    public function getOrderMeta(int $orderId, string $key): mixed
    {
        $order = $this->getOrder($orderId);
        $meta  = collect($order['meta_data'] ?? []);
        return $meta->firstWhere('key', $key)['value'] ?? null;
    }

    public function updateOrderStatus(int $orderId, string $status): bool
    {
        $response = $this->client()->put("{$this->baseUrl}/orders/{$orderId}", [
            'status' => $status,
        ]);
        return $response->successful();
    }
}
