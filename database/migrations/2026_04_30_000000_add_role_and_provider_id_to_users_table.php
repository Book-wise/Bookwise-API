<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['admin', 'provider', 'client'])->default('provider')->after('email');
            $table->unsignedBigInteger('provider_id')->nullable()->after('role');

            $table->foreign('provider_id')
                ->references('id')
                ->on('providers')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['provider_id']);
            $table->dropColumn(['provider_id', 'role']);
        });
    }
};