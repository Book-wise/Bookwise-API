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
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('reminder_sent_at');
            $table->timestamp('reminder_24h_sent_at')->after('end_time')->nullable();
            $table->timestamp('reminder_30m_sent_at')->after('reminder_24h_sent_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['reminder_24h_sent_at', 'reminder_30m_sent_at']);
            $table->timestamp('reminder_sent_at')->after('end_time')->nullable();
        });
    }
};
