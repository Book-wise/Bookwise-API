<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->after('phone')->constrained()->nullOnDelete();
        });

        Schema::dropIfExists('location_provider');
    }

    public function down(): void
    {
        Schema::create('location_provider', function (Blueprint $table) {
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->primary(['location_id', 'provider_id']);
        });

        Schema::table('providers', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropColumn(['location_id']);
        });
    }
};
