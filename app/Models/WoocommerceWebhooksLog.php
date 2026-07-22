<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Status values: received, processing, processed, failed.
 *
 * @property int $id
 * @property string $event
 * @property int|null $wc_order_id
 * @property int|null $wc_entity_id
 * @property string|null $entity_type
 * @property mixed $payload
 * @property string $status
 * @property string|null $error_message
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
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
