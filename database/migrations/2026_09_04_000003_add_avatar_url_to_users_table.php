<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the users.avatar_url column (thumbnail WebP served from the public
     * disk, never the raw upload).
     *
     * Self-hosted avatars for user profiles: the column stores the public URL of
     * the generated thumbnail, `null` when the user has no avatar (the frontend
     * falls back to initials/monogram).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_url', 1024)->nullable()->after('phone');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('avatar_url');
        });
    }
};
