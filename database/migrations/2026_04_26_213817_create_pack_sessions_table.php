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
        Schema::create('pack_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_pack_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('session_number');
            $table->enum('status', ['pending', 'scheduled', 'attended', 'cancelled'])->default('pending');
            $table->timestamp('attended_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pack_sessions');
    }
};
