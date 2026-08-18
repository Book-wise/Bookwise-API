<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds 3 new ClientPacks with their bookings (this week) and Sales with
 * multiple transactions, so the frontend payment tab has realistic data to render.
 *
 * Provider free-slot analysis (verified against ThisWeekBookingsSeeder output):
 *   Provider 1 (María / Centro):     Mon 25 13:00 · Wed 27 09:00 · Fri 29 13:00
 *   Provider 7 (Ana / Providencia):  Tue 26 09:00 · Thu 28 09:00
 *   Provider 3 (Jorge / Centro):     Wed 27 13:00 · Sat 30 13:00
 *
 * Idempotente: client_packs por (client_id, service_pack_id), bookings por
 * (provider_id, location_id, start_time), pack_sessions por
 * (client_pack_id, session_number), sales por client_pack_id y las
 * transacciones se insertan solo si aún no existen para esa venta.
 */
class PackBookingsThisWeekSeeder extends Seeder
{
    public function run(): void
    {
        // Helper idempotente (mismo patrón que TestDataSeeder).
        $upsert = function (string $table, array $key, array $values): int {
            if (DB::table($table)->where($key)->exists()) {
                DB::table($table)->where($key)->update(array_merge($values, ['updated_at' => now()]));
            } else {
                DB::table($table)->insert(array_merge($key, $values, ['created_at' => now(), 'updated_at' => now()]));
            }

            return DB::table($table)->where($key)->value('id');
        };

        // Transacción de venta: solo se inserta si aún no existe una idéntica
        // (clave: sale_id + amount + paid_at). Determinista y sin duplicados.
        $tx = function (array $row): void {
            $exists = DB::table('sale_transactions')
                ->where('sale_id', $row['sale_id'])
                ->where('amount', $row['amount'])
                ->where('paid_at', $row['paid_at'])
                ->exists();

            if (! $exists) {
                DB::table('sale_transactions')->insert(array_merge($row, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }
        };

        // ── Resolve IDs ───────────────────────────────────────────
        $providers = DB::table('providers')->pluck('id', 'email');
        $services = DB::table('services')->pluck('id', 'name');
        $locations = DB::table('locations')->pluck('id', 'name');
        $packs = DB::table('service_packs')->pluck('id', 'name');
        // Pool estable: excluye al cliente demo (HistoricsDemoSeeder) para que los
        // índices de clientes no cambien entre corridas.
        $clients = DB::table('clients')
            ->where('email', '!=', 'demo.historics@mail.com')
            ->orderBy('id')
            ->pluck('id')
            ->values();

        $pMaria = $providers['maria@kinesilk.cl'];
        $pJorge = $providers['jorge@kinesilk.cl'];
        $pAna = $providers['ana@kinesilk.cl'];

        $sRelajante = $services['Masaje Relajante'];
        $sKinesio = $services['Kinesiología'];
        $sDrenaje = $services['Drenaje Linfático'];

        $lCentro = $locations['Kinesilk Centro'];
        $lProvidencia = $locations['Kinesilk Providencia'];

        $packRelajante6 = $packs['Pack Masaje Relajante x6'];
        $packKinesio8 = $packs['Pack Kinesiología x8'];
        $packDrenaje4 = $packs['Pack Drenaje Linfático x4'];

        // Clients not yet assigned to a pack: Valentina(2), Camila(4), Matías(7)
        $cValentina = $clients[2];
        $cCamila = $clients[4];
        $cMatias = $clients[7];

        $statusId = DB::table('booking_statuses')->where('name', 'Confirmado')->value('id') ?? 2;

        // ══════════════════════════════════════════════════════════
        // PACK 1 — Valentina · Relajante x6 · María (Centro)
        // Sessions this week: Mon 25 13:00 | Wed 27 09:00 | Fri 29 13:00
        // Sale: 3 transactions → partial ($153,000 / $189,000)
        // ══════════════════════════════════════════════════════════
        $cp1 = $upsert('client_packs', [
            'client_id' => $cValentina,
            'service_pack_id' => $packRelajante6,
        ], [
            'total_sessions' => 6,
            'used_sessions' => 2,
            'status' => 'active',
        ]);

        $b1Sessions = [
            ['2026-05-25 13:00:00', '2026-05-25 14:00:00', 'attended',  '2026-05-25 14:00:00'],
            ['2026-05-27 09:00:00', '2026-05-27 10:00:00', 'attended',  '2026-05-27 10:00:00'],
            ['2026-05-29 13:00:00', '2026-05-29 14:00:00', 'scheduled', null],
        ];

        $b1Ids = [];
        foreach ($b1Sessions as $i => $sess) {
            $bookingId = $upsert('bookings', [
                'provider_id' => $pMaria,
                'location_id' => $lCentro,
                'start_time' => $sess[0],
            ], [
                'client_id' => $cValentina,
                'service_id' => $sRelajante,
                'status_id' => $statusId,
                'end_time' => $sess[1],
                'price' => 35000,
                'created_via' => 'admin_calendar',
                'last_modified_via' => 'admin_calendar',
            ]);
            $b1Ids[$i] = $bookingId;

            $upsert('pack_sessions', [
                'client_pack_id' => $cp1,
                'session_number' => $i + 1,
            ], [
                'booking_id' => $bookingId,
                'status' => $sess[2],
                'attended_at' => $sess[3],
            ]);
        }

        foreach (range(4, 6) as $n) {
            // Sesión placeholder: solo se crea si no existe para no pisar una
            // sesión ya agendada (PackSessionsScheduleSeeder la vincula después).
            $exists = DB::table('pack_sessions')
                ->where('client_pack_id', $cp1)
                ->where('session_number', $n)
                ->exists();

            if (! $exists) {
                DB::table('pack_sessions')->insert([
                    'client_pack_id' => $cp1,
                    'session_number' => $n,
                    'booking_id' => null,
                    'status' => 'pending',
                    'attended_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // total = 35.000 × 6 sesiones = 210.000
        $sale1 = $upsert('sales', ['client_pack_id' => $cp1], [
            'client_id' => $cValentina,
            'total' => 210000,
            'paid_amount' => 153000,
            'payment_method' => 'transferencia',
            'paid_at' => null,
        ]);

        $tx(['sale_id' => $sale1, 'amount' => 63000, 'payment_method' => 'transferencia', 'notes' => 'Abono inicial — inscripción al pack',    'paid_at' => '2026-05-25 13:00:00']);
        $tx(['sale_id' => $sale1, 'amount' => 50000, 'payment_method' => 'efectivo',      'notes' => 'Abono al momento de la segunda sesión',  'paid_at' => '2026-05-27 09:00:00']);
        $tx(['sale_id' => $sale1, 'amount' => 40000, 'payment_method' => 'transferencia', 'notes' => 'Tercer abono — saldo pendiente $57.000', 'paid_at' => '2026-05-29 13:00:00']);

        // ══════════════════════════════════════════════════════════
        // PACK 2 — Camila · Kinesiología x8 · Ana (Providencia)
        // Sessions this week: Tue 26 09:00 | Thu 28 09:00
        // Sale: 2 transactions → partial ($200,000 / $288,000)
        // ══════════════════════════════════════════════════════════
        $cp2 = $upsert('client_packs', [
            'client_id' => $cCamila,
            'service_pack_id' => $packKinesio8,
        ], [
            'total_sessions' => 8,
            'used_sessions' => 2,
            'status' => 'active',
        ]);

        $b2Ids = [];
        foreach ([
            ['2026-05-26 09:00:00', '2026-05-26 10:00:00'],
            ['2026-05-28 09:00:00', '2026-05-28 10:00:00'],
        ] as $i => $slot) {
            $bookingId = $upsert('bookings', [
                'provider_id' => $pAna,
                'location_id' => $lProvidencia,
                'start_time' => $slot[0],
            ], [
                'client_id' => $cCamila,
                'service_id' => $sKinesio,
                'status_id' => $statusId,
                'end_time' => $slot[1],
                'price' => 40000,
                'created_via' => 'admin_calendar',
                'last_modified_via' => 'admin_calendar',
            ]);
            $b2Ids[$i] = $bookingId;

            $upsert('pack_sessions', [
                'client_pack_id' => $cp2,
                'session_number' => $i + 1,
            ], [
                'booking_id' => $bookingId,
                'status' => 'attended',
                'attended_at' => $slot[1],
            ]);
        }

        foreach (range(3, 8) as $n) {
            // Sesión placeholder: solo se crea si no existe para no pisar una
            // sesión ya agendada (PackSessionsScheduleSeeder la vincula después).
            $exists = DB::table('pack_sessions')
                ->where('client_pack_id', $cp2)
                ->where('session_number', $n)
                ->exists();

            if (! $exists) {
                DB::table('pack_sessions')->insert([
                    'client_pack_id' => $cp2,
                    'session_number' => $n,
                    'booking_id' => null,
                    'status' => 'pending',
                    'attended_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // total = 40.000 × 8 sesiones = 320.000
        $sale2 = $upsert('sales', ['client_pack_id' => $cp2], [
            'client_id' => $cCamila,
            'total' => 320000,
            'paid_amount' => 200000,
            'payment_method' => 'tarjeta',
            'paid_at' => null,
        ]);

        $tx(['sale_id' => $sale2, 'amount' => 100000, 'payment_method' => 'tarjeta',       'notes' => 'Primer abono — inicio de tratamiento kinesiológico', 'paid_at' => '2026-05-26 09:00:00']);
        $tx(['sale_id' => $sale2, 'amount' => 100000, 'payment_method' => 'transferencia', 'notes' => 'Segundo abono — saldo pendiente $120.000',            'paid_at' => '2026-05-28 09:00:00']);

        // ══════════════════════════════════════════════════════════
        // PACK 3 — Matías · Drenaje x4 · Jorge (Centro)
        // Sessions this week: Wed 27 13:00 | Sat 30 13:00
        // Sale: 2 transacciones → pagado completo ($160,000 / $160,000)
        // ══════════════════════════════════════════════════════════
        $cp3 = $upsert('client_packs', [
            'client_id' => $cMatias,
            'service_pack_id' => $packDrenaje4,
        ], [
            'total_sessions' => 4,
            'used_sessions' => 1,
            'status' => 'active',
        ]);

        $b3Ids = [];
        foreach ([
            ['2026-05-27 13:00:00', '2026-05-27 14:30:00', 'attended',  '2026-05-27 14:30:00'],
            ['2026-05-30 13:00:00', '2026-05-30 14:30:00', 'scheduled', null],
        ] as $i => $slot) {
            $bookingId = $upsert('bookings', [
                'provider_id' => $pJorge,
                'location_id' => $lCentro,
                'start_time' => $slot[0],
            ], [
                'client_id' => $cMatias,
                'service_id' => $sDrenaje,
                'status_id' => $statusId,
                'end_time' => $slot[1],
                'price' => 45000,
                'created_via' => 'admin_calendar',
                'last_modified_via' => 'admin_calendar',
            ]);
            $b3Ids[$i] = $bookingId;

            $upsert('pack_sessions', [
                'client_pack_id' => $cp3,
                'session_number' => $i + 1,
            ], [
                'booking_id' => $bookingId,
                'status' => $slot[2],
                'attended_at' => $slot[3],
            ]);
        }

        foreach (range(3, 4) as $n) {
            $upsert('pack_sessions', [
                'client_pack_id' => $cp3,
                'session_number' => $n,
            ], [
                'booking_id' => null,
                'status' => 'pending',
                'attended_at' => null,
            ]);
        }

        // total = 45.000 × 4 sesiones = 180.000 → pago parcial (160.000 / 180.000)
        $sale3 = $upsert('sales', ['client_pack_id' => $cp3], [
            'client_id' => $cMatias,
            'total' => 180000,
            'paid_amount' => 160000,
            'payment_method' => 'efectivo',
            'paid_at' => null,
        ]);

        $tx(['sale_id' => $sale3, 'amount' => 100000, 'payment_method' => 'efectivo',      'notes' => 'Abono inicial — pack drenaje linfático', 'paid_at' => '2026-05-27 13:00:00']);
        $tx(['sale_id' => $sale3, 'amount' => 60000, 'payment_method' => 'transferencia', 'notes' => 'Segundo abono — saldo pendiente $20.000', 'paid_at' => '2026-05-28 10:00:00']);
    }
}
