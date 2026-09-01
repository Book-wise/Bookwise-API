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
        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('email_new_booking')->after('notifications_enabled')->default(true);
            $table->boolean('email_booking_confirmation')->after('email_new_booking')->default(true);
            $table->boolean('email_booking_cancellation')->after('email_booking_confirmation')->default(true);
            $table->boolean('whatsapp_reminder')->after('email_booking_cancellation')->default(true);
            $table->boolean('whatsapp_cancellation_confirmation')->after('whatsapp_reminder')->default(true);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->index('start_time', 'bookings_start_time_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('bookings_start_time_index');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn([
                'email_new_booking',
                'email_booking_confirmation',
                'email_booking_cancellation',
                'whatsapp_reminder',
                'whatsapp_cancellation_confirmation',
            ]);
        });
    }
};
