<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumn('booking_statuses', 'is_finalized')) {
            return;
        }

        Schema::table('booking_statuses', function (Blueprint $table) {
            $table->boolean('is_finalized')->default(false)->after('is_cancellation');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('booking_statuses', function (Blueprint $table) {
            $table->dropColumn('is_finalized');
        });
    }
};
