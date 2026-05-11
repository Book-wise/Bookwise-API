<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WoocommerceWebhooksLog extends Model
{
    protected $table = 'woocommerce_webhooks_log';

    protected $fillable = [
        'event',
        'wc_order_id',
        'wc_entity_id',
        'entity_type',
        'payload',
        'status',
        'error_message',
    ];

    protected $casts = [
        'payload' => 'array',
    ];
}
