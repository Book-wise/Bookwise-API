<?php

use App\Services\ProviderStaffBackfillService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Assigns the default `staff` business role to providers holding none, so
     * existing professionals become selectable for bookings. Idempotent and
     * conservative (see App\Services\ProviderStaffBackfillService): a data
     * backfill — safe to run against existing data and harmless on a fresh DB
     * (no providers exist yet, so it no-ops before seeders/fixtures run).
     */
    public function up(): void
    {
        app(ProviderStaffBackfillService::class)->backfill();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Data backfill is intentionally not reversible.
    }
};
