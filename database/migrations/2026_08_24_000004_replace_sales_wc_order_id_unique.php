<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replace the plain unique index on `wc_order_id` with a generated
     * `wc_order_id_active` key so a soft-deleted sale no longer blocks a
     * re-synced sale with the same WooCommerce order id.
     *
     * Portable across MySQL (prod) and SQLite (tests: `:memory:`).
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropUnique(['wc_order_id']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->unsignedBigInteger('wc_order_id_active')
                ->nullable()
                ->storedAs('CASE WHEN deleted_at IS NULL THEN wc_order_id ELSE NULL END');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->unique('wc_order_id_active');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropUnique(['wc_order_id_active']);
            $table->dropColumn('wc_order_id_active');
            $table->unique('wc_order_id');
        });
    }
};
