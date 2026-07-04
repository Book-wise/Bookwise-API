<?php

namespace App\Services;

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
            $sale = Sale::create([
                'booking_id' => $booking->id,
                'client_id' => $booking->client_id,
                'wc_order_id' => $paymentData['wc_order_id'],
                'total' => $paymentData['total'],
                'paid_amount' => $paymentData['total'],
                'payment_method' => $paymentData['payment_method'] ?? 'online',
                'paid_at' => $paymentData['paid_at'] ?? now(),
            ]);

            $sale->transactions()->create([
                'amount' => $paymentData['total'],
                'payment_method' => $paymentData['payment_method'] ?? 'online',
                'paid_at' => $paymentData['paid_at'] ?? now(),
                'notes' => 'Transaction for WooCommerce order #'.$paymentData['wc_order_id'],
            ]);

            return $sale->load('transactions');
        });
    }
}
