<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // Drop the foreign key first, then modify the column
            $table->dropForeign(['provider_id']);
            $table->foreignId('provider_id')->nullable()->change();
            $table->foreign('provider_id')->references('id')->on('providers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign(['provider_id']);
            $table->foreignId('provider_id')->nullable(false)->change();
            $table->foreign('provider_id')->references('id')->on('providers')->cascadeOnDelete();
        });
    }
};
