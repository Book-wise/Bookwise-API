<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            // Make booking_id nullable — a sale can belong to a pack instead
            $table->dropForeign(['booking_id']);
            $table->unsignedBigInteger('booking_id')->nullable()->change();
            $table->foreign('booking_id')->references('id')->on('bookings')->nullOnDelete();

            // Link to the client pack when the sale covers a full pack purchase
            $table->foreignId('client_pack_id')
                ->nullable()
                ->after('booking_id')
                ->constrained('client_packs')
                ->nullOnDelete();

            // Denormalized client reference for direct client → sales queries
            $table->foreignId('client_id')
                ->nullable()
                ->after('client_pack_id')
                ->constrained('clients')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropForeign(['client_id']);
            $table->dropColumn('client_id');

            $table->dropForeign(['client_pack_id']);
            $table->dropColumn('client_pack_id');

            $table->dropForeign(['booking_id']);
            $table->unsignedBigInteger('booking_id')->nullable(false)->change();
            $table->foreign('booking_id')->references('id')->on('bookings')->cascadeOnDelete();
        });
    }
};
