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
 */
class PackBookingsThisWeekSeeder extends Seeder
{
    public function run(): void
    {
        // ── Resolve IDs ───────────────────────────────────────────
        $providers = DB::table('providers')->pluck('id', 'email');
        $services  = DB::table('services')->pluck('id', 'name');
        $locations = DB::table('locations')->pluck('id', 'name');
        $packs     = DB::table('service_packs')->pluck('id', 'name');
        $clients   = DB::table('clients')->orderBy('id')->pluck('id')->values();

        $pMaria = $providers['maria@kinesilk.cl'];
        $pJorge = $providers['jorge@kinesilk.cl'];
        $pAna   = $providers['ana@kinesilk.cl'];

        $sRelajante = $services['Masaje Relajante'];
        $sKinesio   = $services['Kinesiología'];
        $sDrenaje   = $services['Drenaje Linfático'];

        $lCentro      = $locations['Kinesilk Centro'];
        $lProvidencia = $locations['Kinesilk Providencia'];

        $packRelajante6 = $packs['Pack Masaje Relajante x6'];
        $packKinesio8   = $packs['Pack Kinesiología x8'];
        $packDrenaje4   = $packs['Pack Drenaje Linfático x4'];

        // Clients not yet assigned to a pack: Valentina(2), Camila(4), Matías(7)
        $cValentina = $clients[2];
        $cCamila    = $clients[4];
        $cMatias    = $clients[7];

        $statusId = DB::table('booking_statuses')->where('name', 'Confirmado')->value('id') ?? 2;

        // ══════════════════════════════════════════════════════════
        // PACK 1 — Valentina · Relajante x6 · María (Centro)
        // Sessions this week: Mon 25 13:00 | Wed 27 09:00 | Fri 29 13:00
        // Sale: 3 transactions → partial ($153,000 / $189,000)
        // ══════════════════════════════════════════════════════════
        $cp1 = DB::table('client_packs')->insertGetId([
            'client_id'       => $cValentina,
            'service_pack_id' => $packRelajante6,
            'total_sessions'  => 6,
            'used_sessions'   => 2,
            'status'          => 'active',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $b1Sessions = [
            ['2026-05-25 13:00:00', '2026-05-25 14:00:00', 'attended',  '2026-05-25 14:00:00'],
            ['2026-05-27 09:00:00', '2026-05-27 10:00:00', 'attended',  '2026-05-27 10:00:00'],
            ['2026-05-29 13:00:00', '2026-05-29 14:00:00', 'scheduled', null],
        ];

        $b1Ids = [];
        foreach ($b1Sessions as $sess) {
            $b1Ids[] = DB::table('bookings')->insertGetId([
                'client_id'   => $cValentina,
                'service_id'  => $sRelajante,
                'provider_id' => $pMaria,
                'location_id' => $lCentro,
                'status_id'   => $statusId,
                'start_time'  => $sess[0],
                'end_time'    => $sess[1],
                'price'       => 35000,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }

        DB::table('pack_sessions')->insert([
            ['client_pack_id' => $cp1, 'booking_id' => $b1Ids[0], 'session_number' => 1, 'status' => 'attended',  'attended_at' => '2026-05-25 14:00:00', 'created_at' => now(), 'updated_at' => now()],
            ['client_pack_id' => $cp1, 'booking_id' => $b1Ids[1], 'session_number' => 2, 'status' => 'attended',  'attended_at' => '2026-05-27 10:00:00', 'created_at' => now(), 'updated_at' => now()],
            ['client_pack_id' => $cp1, 'booking_id' => $b1Ids[2], 'session_number' => 3, 'status' => 'scheduled', 'attended_at' => null,                  'created_at' => now(), 'updated_at' => now()],
            ['client_pack_id' => $cp1, 'booking_id' => null,       'session_number' => 4, 'status' => 'pending',   'attended_at' => null,                  'created_at' => now(), 'updated_at' => now()],
            ['client_pack_id' => $cp1, 'booking_id' => null,       'session_number' => 5, 'status' => 'pending',   'attended_at' => null,                  'created_at' => now(), 'updated_at' => now()],
            ['client_pack_id' => $cp1, 'booking_id' => null,       'session_number' => 6, 'status' => 'pending',   'attended_at' => null,                  'created_at' => now(), 'updated_at' => now()],
        ]);

        // total = 35.000 × 6 sesiones = 210.000
        $sale1 = DB::table('sales')->insertGetId([
            'client_pack_id' => $cp1,
            'client_id'      => $cValentina,
            'total'          => 210000,
            'paid_amount'    => 153000,
            'payment_method' => 'transferencia',
            'paid_at'        => null,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        DB::table('sale_transactions')->insert([
            ['sale_id' => $sale1, 'amount' => 63000, 'payment_method' => 'transferencia', 'notes' => 'Abono inicial — inscripción al pack',    'paid_at' => '2026-05-25 13:00:00', 'created_at' => now(), 'updated_at' => now()],
            ['sale_id' => $sale1, 'amount' => 50000, 'payment_method' => 'efectivo',      'notes' => 'Abono al momento de la segunda sesión',  'paid_at' => '2026-05-27 09:00:00', 'created_at' => now(), 'updated_at' => now()],
            ['sale_id' => $sale1, 'amount' => 40000, 'payment_method' => 'transferencia', 'notes' => 'Tercer abono — saldo pendiente $57.000', 'paid_at' => '2026-05-29 13:00:00', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // ══════════════════════════════════════════════════════════
        // PACK 2 — Camila · Kinesiología x8 · Ana (Providencia)
        // Sessions this week: Tue 26 09:00 | Thu 28 09:00
        // Sale: 2 transactions → partial ($200,000 / $288,000)
        // ══════════════════════════════════════════════════════════
        $cp2 = DB::table('client_packs')->insertGetId([
            'client_id'       => $cCamila,
            'service_pack_id' => $packKinesio8,
            'total_sessions'  => 8,
            'used_sessions'   => 2,
            'status'          => 'active',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $b2Ids = [];
        foreach ([
            ['2026-05-26 09:00:00', '2026-05-26 10:00:00'],
            ['2026-05-28 09:00:00', '2026-05-28 10:00:00'],
        ] as $slot) {
            $b2Ids[] = DB::table('bookings')->insertGetId([
                'client_id'   => $cCamila,
                'service_id'  => $sKinesio,
                'provider_id' => $pAna,
                'location_id' => $lProvidencia,
                'status_id'   => $statusId,
                'start_time'  => $slot[0],
                'end_time'    => $slot[1],
                'price'       => 40000,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }

        $pendingSessions = array_map(
            fn($n) => ['client_pack_id' => $cp2, 'booking_id' => null, 'session_number' => $n, 'status' => 'pending', 'attended_at' => null, 'created_at' => now(), 'updated_at' => now()],
            range(3, 8)
        );

        DB::table('pack_sessions')->insert(array_merge([
            ['client_pack_id' => $cp2, 'booking_id' => $b2Ids[0], 'session_number' => 1, 'status' => 'attended', 'attended_at' => '2026-05-26 10:00:00', 'created_at' => now(), 'updated_at' => now()],
            ['client_pack_id' => $cp2, 'booking_id' => $b2Ids[1], 'session_number' => 2, 'status' => 'attended', 'attended_at' => '2026-05-28 10:00:00', 'created_at' => now(), 'updated_at' => now()],
        ], $pendingSessions));

        // total = 40.000 × 8 sesiones = 320.000
        $sale2 = DB::table('sales')->insertGetId([
            'client_pack_id' => $cp2,
            'client_id'      => $cCamila,
            'total'          => 320000,
            'paid_amount'    => 200000,
            'payment_method' => 'tarjeta',
            'paid_at'        => null,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        DB::table('sale_transactions')->insert([
            ['sale_id' => $sale2, 'amount' => 100000, 'payment_method' => 'tarjeta',       'notes' => 'Primer abono — inicio de tratamiento kinesiológico', 'paid_at' => '2026-05-26 09:00:00', 'created_at' => now(), 'updated_at' => now()],
            ['sale_id' => $sale2, 'amount' => 100000, 'payment_method' => 'transferencia', 'notes' => 'Segundo abono — saldo pendiente $120.000',            'paid_at' => '2026-05-28 09:00:00', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // ══════════════════════════════════════════════════════════
        // PACK 3 — Matías · Drenaje x4 · Jorge (Centro)
        // Sessions this week: Wed 27 13:00 | Sat 30 13:00
        // Sale: 2 transacciones → pagado completo ($160,000 / $160,000)
        // ══════════════════════════════════════════════════════════
        $cp3 = DB::table('client_packs')->insertGetId([
            'client_id'       => $cMatias,
            'service_pack_id' => $packDrenaje4,
            'total_sessions'  => 4,
            'used_sessions'   => 1,
            'status'          => 'active',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $b3Ids = [];
        foreach ([
            ['2026-05-27 13:00:00', '2026-05-27 14:30:00', 'attended',  '2026-05-27 14:30:00'],
            ['2026-05-30 13:00:00', '2026-05-30 14:30:00', 'scheduled', null],
        ] as $slot) {
            $b3Ids[] = DB::table('bookings')->insertGetId([
                'client_id'   => $cMatias,
                'service_id'  => $sDrenaje,
                'provider_id' => $pJorge,
                'location_id' => $lCentro,
                'status_id'   => $statusId,
                'start_time'  => $slot[0],
                'end_time'    => $slot[1],
                'price'       => 45000,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }

        DB::table('pack_sessions')->insert([
            ['client_pack_id' => $cp3, 'booking_id' => $b3Ids[0], 'session_number' => 1, 'status' => 'attended',  'attended_at' => '2026-05-27 14:30:00', 'created_at' => now(), 'updated_at' => now()],
            ['client_pack_id' => $cp3, 'booking_id' => $b3Ids[1], 'session_number' => 2, 'status' => 'scheduled', 'attended_at' => null,                  'created_at' => now(), 'updated_at' => now()],
            ['client_pack_id' => $cp3, 'booking_id' => null,       'session_number' => 3, 'status' => 'pending',   'attended_at' => null,                  'created_at' => now(), 'updated_at' => now()],
            ['client_pack_id' => $cp3, 'booking_id' => null,       'session_number' => 4, 'status' => 'pending',   'attended_at' => null,                  'created_at' => now(), 'updated_at' => now()],
        ]);

        // total = 45.000 × 4 sesiones = 180.000 → pago parcial (160.000 / 180.000)
        $sale3 = DB::table('sales')->insertGetId([
            'client_pack_id' => $cp3,
            'client_id'      => $cMatias,
            'total'          => 180000,
            'paid_amount'    => 160000,
            'payment_method' => 'efectivo',
            'paid_at'        => null,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        DB::table('sale_transactions')->insert([
            ['sale_id' => $sale3, 'amount' => 100000, 'payment_method' => 'efectivo',      'notes' => 'Abono inicial — pack drenaje linfático', 'paid_at' => '2026-05-27 13:00:00', 'created_at' => now(), 'updated_at' => now()],
            ['sale_id' => $sale3, 'amount' =>  60000, 'payment_method' => 'transferencia', 'notes' => 'Segundo abono — saldo pendiente $20.000', 'paid_at' => '2026-05-28 10:00:00', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}
