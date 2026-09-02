<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Backfill terminal booking statuses as finalized so the
     * live/final predicate (is_cancellation = false AND is_finalized = false)
     * matches the front contract exactly: {1, 2, 5, 6} live, {3, 4, 7} final.
     *
     * Idempotent: re-updating already-true rows is a no-op.
     */
    public function up(): void
    {
        DB::table('booking_statuses')
            ->whereIn('id', [3, 4, 7])
            ->update(['is_finalized' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('booking_statuses')
            ->whereIn('id', [3, 4, 7])
            ->update(['is_finalized' => false]);
    }
};
