<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    protected $fillable = [
        'booking_id', 'client_pack_id', 'client_id', 'wc_order_id',
        'total', 'paid_amount', 'payment_method', 'paid_at',
    ];

    protected $casts = [
        'total'       => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'paid_at'     => 'datetime',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function clientPack()
    {
        return $this->belongsTo(ClientPack::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function transactions()
    {
        return $this->hasMany(SaleTransaction::class)->orderBy('paid_at');
    }

    public function recalculatePaidAmount(): void
    {
        $this->update(['paid_amount' => $this->transactions()->sum('amount')]);
    }

    public function getRemainingAmountAttribute(): float
    {
        return (float) $this->total - (float) $this->paid_amount;
    }

    public function getPaymentStatusAttribute(): string
    {
        $paid  = (float) $this->paid_amount;
        $total = (float) $this->total;

        if ($paid <= 0)      return 'unpaid';
        if ($paid >= $total) return 'paid';
        return 'partial';
    }
}
