<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Models\Booking;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;

class SaleService
{
    /**
     * Create a Sale and its first SaleTransaction from a Booking's payment data.
     * All wrapped in a single DB transaction.
     */
    public function createFromBooking(Booking $booking, array $paymentData): Sale
    {
        return DB::transaction(function () use ($booking, $paymentData) {
            $paymentMethod = PaymentMethod::fromLegacy($paymentData['payment_method'] ?? 'online');

            $sale = Sale::create([
                'booking_id' => $booking->id,
                'client_id' => $booking->client_id,
                'wc_order_id' => $paymentData['wc_order_id'],
                'total' => $paymentData['total'],
                'paid_amount' => $paymentData['total'],
                'payment_method' => $paymentMethod,
                'paid_at' => $paymentData['paid_at'] ?? now(),
            ]);

            $sale->transactions()->create([
                'amount' => $paymentData['total'],
                'payment_method' => $paymentMethod,
                'paid_at' => $paymentData['paid_at'] ?? now(),
                'notes' => 'Transaction for WooCommerce order #'.$paymentData['wc_order_id'],
            ]);

            return $sale->load('transactions');
        });
    }
}
