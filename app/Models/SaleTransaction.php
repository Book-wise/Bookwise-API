<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Model;

class SaleTransaction extends Model
{
    protected $fillable = [
        'sale_id', 'amount', 'payment_method', 'notes', 'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'payment_method' => PaymentMethod::class,
    ];

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }
}
