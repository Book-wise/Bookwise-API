<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('woocommerce_webhooks_log', function (Blueprint $table) {
            $table->unsignedBigInteger('wc_entity_id')->nullable()->after('wc_order_id');
            $table->enum('entity_type', ['order', 'customer'])->nullable()->after('wc_entity_id');
        });
    }

    public function down(): void
    {
        Schema::table('woocommerce_webhooks_log', function (Blueprint $table) {
            $table->dropColumn(['wc_entity_id', 'entity_type']);
        });
    }
};
