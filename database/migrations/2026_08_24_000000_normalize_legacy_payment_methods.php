<?php

use App\Enums\PaymentMethod;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Reconcile legacy payment_method values on sales and sale_transactions:
     *   - `tarjeta`     -> `crédito`
     *   - `credit_card` -> `online`
     *   - any other non-null, non-canonical value -> `otro`
     *
     * Canonical values and null are left untouched.
     */
    public function up(): void
    {
        $canonical = array_map(fn (PaymentMethod $case) => $case->value, PaymentMethod::cases());

        foreach (['sales', 'sale_transactions'] as $table) {
            DB::table($table)
                ->where('payment_method', 'tarjeta')
                ->update(['payment_method' => PaymentMethod::CREDITO->value]);

            DB::table($table)
                ->where('payment_method', 'credit_card')
                ->update(['payment_method' => PaymentMethod::ONLINE->value]);

            DB::table($table)
                ->whereNotNull('payment_method')
                ->whereNotIn('payment_method', $canonical)
                ->update(['payment_method' => PaymentMethod::OTRO->value]);
        }
    }

    /**
     * Reverse the migrations.
     *
     * The normalization is intentionally lossy (`tarjeta` and `credit_card`
     * collapse into canonical values), so a faithful reverse mapping is not
     * possible. No-op.
     */
    public function down(): void
    {
        //
    }
};
