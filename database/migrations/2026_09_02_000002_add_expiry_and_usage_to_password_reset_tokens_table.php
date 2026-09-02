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
        Schema::table('password_reset_tokens', function (Blueprint $table) {
            // REQ-1: extend the framework table in place — email stays the PK
            // (one active row per email, last-wins overwrite); `token` keeps
            // storing the sha256 hex (MD1/D2). Table is empty (0 rows), so no
            // backfill. No FKs: email is identity, immutable.
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations — restore the framework table shape.
     */
    public function down(): void
    {
        Schema::table('password_reset_tokens', function (Blueprint $table) {
            $table->dropColumn(['expires_at', 'used_at', 'updated_at']);
        });
    }
};
