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
        Schema::table('services', function (Blueprint $table) {
            $table->unsignedSmallInteger('slot_interval_minutes')->default(30)->after('duration_minutes');
            $table->unsignedSmallInteger('min_duration_minutes')->nullable()->after('slot_interval_minutes');
            $table->unsignedSmallInteger('max_duration_minutes')->nullable()->after('min_duration_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['slot_interval_minutes','min_duration_minutes','max_duration_minutes']);
        });
    }
};
