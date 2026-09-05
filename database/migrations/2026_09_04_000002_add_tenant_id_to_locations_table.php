<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            // Pertenencia dura: cada sucursal pertenece a un tenant (multi-tenant).
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        // Backfill: las sucursales existentes pertenecen al tenant 1 (Bookwise SpA).
        DB::table('locations')->whereNull('tenant_id')->update(['tenant_id' => 1]);
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
        });
    }
};
