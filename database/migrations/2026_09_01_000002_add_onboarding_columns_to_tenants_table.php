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
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('business_email', 255)->nullable()->after('business_rut');
            $table->string('business_address', 255)->nullable()->after('business_email');
            $table->string('business_phone', 50)->nullable()->after('business_address');
            $table->string('business_plan', 50)->nullable()->default('starter')->after('business_phone');

            // Nullable column: MySQL/SQLite allow multiple NULLs, so legacy
            // RUT-less tenants are unaffected; onboarding enforces uniqueness.
            $table->unique('business_rut', 'tenants_business_rut_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropUnique('tenants_business_rut_unique');
            $table->dropColumn(['business_email', 'business_address', 'business_phone', 'business_plan']);
        });
    }
};
