<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SalesTransactionsSeeder extends Seeder
{
    private array $methods = ['transferencia', 'efectivo', 'tarjeta', 'débito'];

    private int $mIdx = 0;

    public function run(): void
    {
        // ── 1. Backfill client_id en ventas existentes ────────────
        DB::statement('
            UPDATE sales
            INNER JOIN bookings ON sales.booking_id = bookings.id
            SET sales.client_id = bookings.client_id
            WHERE sales.client_id IS NULL AND sales.booking_id IS NOT NULL
        ');

        // ── 2. Crear transacciones para ventas ya existentes ──────
        // TestDataSeeder insertó sales directamente con paid_amount.
        // Creamos transacciones que reflejen ese estado para el tab de pagos.
        $existingSales = DB::table('sales')
            ->where('paid_amount', '>', 0)
            ->whereNotExists(fn ($q) => $q->from('sale_transactions')->whereColumn('sale_transactions.sale_id', 'sales.id'))
            ->get();

        foreach ($existingSales as $sale) {
            $paid = (float) $sale->paid_amount;
            $total = (float) $sale->total;

            if ($paid >= $total) {
                // Pagado completo — algunos en 2 cuotas para variedad
                if ($sale->id % 3 === 0) {
                    $primera = (int) round($paid * 0.6);
                    $this->insertTransaction($sale->id, $primera, 'Primer abono', now()->subDays(7));
                    $this->insertTransaction($sale->id, $paid - $primera, 'Saldo cancelado', now()->subDays(2));
                } else {
                    $this->insertTransaction($sale->id, $paid, 'Pago total de la sesión', now()->subDays(rand(1, 5)));
                }
            } else {
                // Pago parcial — un abono
                $this->insertTransaction($sale->id, $paid, 'Abono inicial', now()->subDays(rand(1, 4)));
            }
        }

        // ── 3. Ventas para los client_packs existentes ────────────
        // total = service.price × total_sessions — nunca service_pack.price
        $clientPacks = DB::table('client_packs')
            ->join('service_packs', 'client_packs.service_pack_id', '=', 'service_packs.id')
            ->join('services', 'service_packs.service_id', '=', 'services.id')
            ->select(
                'client_packs.*',
                'service_packs.name as pack_name',
                'services.price as session_price'
            )
            ->whereNotExists(fn ($q) => $q->from('sales')->whereColumn('sales.client_pack_id', 'client_packs.id'))
            ->get();

        $packStates = ['paid', 'partial', 'unpaid'];

        foreach ($clientPacks as $i => $cp) {
            $state = $packStates[$i % count($packStates)];
            $total = (float) $cp->session_price * (int) $cp->total_sessions;

            $paidAmount = match ($state) {
                'paid' => $total,
                'partial' => (int) round($total * 0.4),
                default => 0,
            };

            $saleId = DB::table('sales')->insertGetId([
                'client_pack_id' => $cp->id,
                'client_id' => $cp->client_id,
                'total' => $total,
                'paid_amount' => $paidAmount,
                'payment_method' => $state !== 'unpaid' ? $this->method() : null,
                'paid_at' => $state === 'paid' ? now()->subDays(rand(3, 10)) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($state === 'paid') {
                $primera = (int) round($total * 0.5);
                $this->insertTransaction($saleId, $primera, "Primer abono — {$cp->pack_name}", now()->subDays(10));
                $this->insertTransaction($saleId, $total - $primera, 'Saldo total del pack cancelado', now()->subDays(3));
            } elseif ($state === 'partial') {
                $this->insertTransaction($saleId, $paidAmount, "Abono inicial — {$cp->pack_name}", now()->subDays(5));
            }
        }

        // ── 4. Ventas para reservas pasadas sin sale (semana actual) ──
        // Excluye bookings que son sesiones de pack — su pack ya tiene su propia sale
        $pastBookings = DB::table('bookings')
            ->leftJoin('sales', 'bookings.id', '=', 'sales.booking_id')
            ->whereNull('sales.id')
            ->whereDate('bookings.start_time', '>=', '2026-05-25')
            ->whereDate('bookings.start_time', '<', now()->toDateString())
            ->whereNotExists(fn ($q) => $q->from('pack_sessions')->whereColumn('pack_sessions.booking_id', 'bookings.id'))
            ->select('bookings.id', 'bookings.client_id', 'bookings.price', 'bookings.service_id')
            ->get();

        $pastStates = ['paid', 'paid', 'partial', 'unpaid', 'paid', 'partial'];
        $stateIdx = 0;

        foreach ($pastBookings as $booking) {
            $state = $pastStates[$stateIdx % count($pastStates)];
            $total = (float) $booking->price;

            $paidAmount = match ($state) {
                'paid' => $total,
                'partial' => (int) round($total * 0.5),
                default => 0,
            };

            $method = $state !== 'unpaid' ? $this->method() : null;

            $saleId = DB::table('sales')->insertGetId([
                'booking_id' => $booking->id,
                'client_id' => $booking->client_id,
                'total' => $total,
                'paid_amount' => $paidAmount,
                'payment_method' => $method,
                'paid_at' => $state === 'paid' ? now()->subDays(1) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($state === 'paid') {
                $this->insertTransaction($saleId, $total, 'Pago al momento de la sesión', now()->subDays(1));
            } elseif ($state === 'partial') {
                $this->insertTransaction($saleId, $paidAmount, 'Abono parcial', now()->subDays(rand(1, 3)));
            }

            $stateIdx++;
        }

        // ── 5. Anticipos en reservas futuras (primera semana de junio) ─
        $futureBookings = DB::table('bookings')
            ->leftJoin('sales', 'bookings.id', '=', 'sales.booking_id')
            ->whereNull('sales.id')
            ->whereDate('bookings.start_time', '>=', '2026-06-01')
            ->whereDate('bookings.start_time', '<=', '2026-06-07')
            ->whereNotExists(fn ($q) => $q->from('pack_sessions')->whereColumn('pack_sessions.booking_id', 'bookings.id'))
            ->select('bookings.id', 'bookings.client_id', 'bookings.price')
            ->orderBy('bookings.start_time')
            ->get();

        // Solo la mitad recibe anticipo — excluye sesiones de pack
        foreach ($futureBookings->chunk(2) as $pair) {
            $booking = $pair->first();
            $total = (float) $booking->price;
            $anticipo = (int) round($total * 0.3);

            $saleId = DB::table('sales')->insertGetId([
                'booking_id' => $booking->id,
                'client_id' => $booking->client_id,
                'total' => $total,
                'paid_amount' => $anticipo,
                'payment_method' => 'transferencia',
                'paid_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->insertTransaction(
                $saleId,
                $anticipo,
                'Anticipo de reserva — saldo pendiente al momento de la sesión',
                now()->subDays(rand(1, 4))
            );
        }
    }

    private function insertTransaction(int $saleId, float $amount, ?string $notes, \DateTimeInterface $paidAt): void
    {
        DB::table('sale_transactions')->insert([
            'sale_id' => $saleId,
            'amount' => $amount,
            'payment_method' => $this->method(),
            'notes' => $notes,
            'paid_at' => $paidAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function method(): string
    {
        return $this->methods[$this->mIdx++ % count($this->methods)];
    }
}
