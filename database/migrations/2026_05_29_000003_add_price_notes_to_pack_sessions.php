<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pack_sessions', function (Blueprint $table) {
            // null = use service.price; set = admin override for this specific session
            $table->decimal('price', 10, 2)->nullable()->after('attended_at');
            $table->string('notes', 500)->nullable()->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('pack_sessions', function (Blueprint $table) {
            $table->dropColumn(['price', 'notes']);
        });
    }
};
