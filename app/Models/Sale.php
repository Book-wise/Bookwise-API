<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sale extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'booking_id', 'client_pack_id', 'client_id', 'wc_order_id',
        'total', 'paid_amount', 'payment_method', 'paid_at',
    ];

    protected $casts = [
        'total' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'payment_method' => PaymentMethod::class,
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
        $paid = (float) $this->paid_amount;
        $total = (float) $this->total;

        if ($paid <= 0) {
            return 'unpaid';
        }
        if ($paid >= $total) {
            return 'paid';
        }

        return 'partial';
    }

    /**
     * Spanish label for payment status — used only in PDF/presentation.
     */
    public function getPaymentStatusLabelAttribute(): string
    {
        return match ($this->payment_status) {
            'paid' => 'Pagado',
            'partial' => 'Parcial',
            default => 'Pendiente',
        };
    }
}
