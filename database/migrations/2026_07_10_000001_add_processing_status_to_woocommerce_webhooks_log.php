<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the 'processing' status to the woocommerce_webhooks_log status column.
     *
     * The original migration used enum('received', 'processed', 'failed'), but the
     * async webhook job needs 'processing' to track in-progress state. SQLite
     * requires recreating the table to alter a CHECK constraint; MySQL can
     * modify the column directly.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            // SQLite requires recreating the table to alter a CHECK constraint.
            // Rebuild with all current columns plus the updated status CHECK.
            DB::statement('CREATE TABLE woocommerce_webhooks_log_new (id INTEGER PRIMARY KEY AUTOINCREMENT, event VARCHAR NOT NULL, wc_order_id INTEGER NULL, wc_entity_id INTEGER NULL, entity_type VARCHAR NULL, payload JSON NOT NULL, status TEXT NOT NULL DEFAULT \'received\' CHECK (status IN (\'received\', \'processing\', \'processed\', \'failed\')), error_message TEXT NULL, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL)');
            DB::statement('INSERT INTO woocommerce_webhooks_log_new (id, event, wc_order_id, wc_entity_id, entity_type, payload, status, error_message, created_at, updated_at) SELECT id, event, wc_order_id, wc_entity_id, entity_type, payload, status, error_message, created_at, updated_at FROM woocommerce_webhooks_log');
            DB::statement('DROP TABLE woocommerce_webhooks_log');
            DB::statement('ALTER TABLE woocommerce_webhooks_log_new RENAME TO woocommerce_webhooks_log');
        } else {
            Schema::table('woocommerce_webhooks_log', function (Blueprint $table) {
                $table->string('status', 20)->default('received')->change();
            });
        }
    }

    /**
     * Revert: restore the original status constraint without 'processing'.
     */
    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            DB::statement('CREATE TABLE woocommerce_webhooks_log_old (id INTEGER PRIMARY KEY AUTOINCREMENT, event VARCHAR NOT NULL, wc_order_id INTEGER NULL, wc_entity_id INTEGER NULL, entity_type VARCHAR NULL, payload JSON NOT NULL, status TEXT NOT NULL DEFAULT \'received\' CHECK (status IN (\'received\', \'processed\', \'failed\')), error_message TEXT NULL, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL)');
            DB::statement('INSERT INTO woocommerce_webhooks_log_old SELECT * FROM woocommerce_webhooks_log');
            DB::statement('DROP TABLE woocommerce_webhooks_log');
            DB::statement('ALTER TABLE woocommerce_webhooks_log_old RENAME TO woocommerce_webhooks_log');
        } else {
            Schema::table('woocommerce_webhooks_log', function (Blueprint $table) {
                $table->string('status', 20)->default('received')->change();
            });
        }
    }
};
