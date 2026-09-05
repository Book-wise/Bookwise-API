<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the users.phone column and backfill email_verified_at for legacy users.
     *
     * Deliberate DDL + DML exception (R1.2): the login verified-gate would lock
     * out existing (seeded/legacy) users whose email_verified_at is null, so the
     * same migration backfills them. The whereNull guard makes it idempotent and
     * safe on an empty table. The backfill is forward-only DML: down() never
     * un-verifies users.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 255)->nullable()->after('email');
        });

        DB::table('users')
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => now()]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('phone');
        });
    }
};
