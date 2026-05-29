<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Schedules future June bookings for all pending pack sessions so every sale
 * linked to a client_pack returns complete session data in GET /sales/:id.
 *
 * Strategy: all pack sessions use the 12:00 slot — verified free across all
 * providers in June (JuneBookingsSeeder only occupies 09:00–11:xx and 14:00+).
 *
 * Provider assignment per service type (providers free at 12:00 in June):
 *   svc1 Relajante (60min) → Pilar p5 loc2  — Tuesdays
 *   svc3 Kinesio   (60min) → Carlos p4 loc2 — Wednesdays
 *   svc4 Drenaje   (90min) → Claudia p9 loc3 — Saturdays
 */
class PackSessionsScheduleSeeder extends Seeder
{
    public function run(): void
    {
        $providers = DB::table('providers')->pluck('id', 'email');
        $locations = DB::table('locations')->pluck('id', 'name');
        $services  = DB::table('services')->pluck('id', 'name');
        $statuses  = DB::table('booking_statuses')->pluck('id', 'name');

        $pPilar   = $providers['pilar@kinesilk.cl'];
        $pCarlos  = $providers['carlos@kinesilk.cl'];
        $pClaudia = $providers['claudia@kinesilk.cl'];

        $lLasCondes   = $locations['Kinesilk Las Condes'];
        $lProvidencia = $locations['Kinesilk Providencia'];

        $sRelajante = $services['Masaje Relajante'];
        $sKinesio   = $services['Kinesiología'];
        $sDrenaje   = $services['Drenaje Linfático'];

        $statusReservado = $statuses['Reservado'] ?? 1;

        // Tuesdays in June 2026 (for Relajante)
        $tuesdays  = ['2026-06-02', '2026-06-09', '2026-06-16', '2026-06-23', '2026-06-30'];
        // Wednesdays in June 2026 (for Kinesio)
        $wednesdays = ['2026-06-03', '2026-06-10', '2026-06-17', '2026-06-24'];
        // Saturdays in June 2026 (for Drenaje)
        $saturdays  = ['2026-06-06', '2026-06-13', '2026-06-20', '2026-06-27'];

        // Service config: [service_id, provider_id, location_id, duration_min, price, dates_pool]
        $config = [
            $sRelajante => [$pPilar,   $lLasCondes,   60, 35000, $tuesdays],
            $sKinesio   => [$pCarlos,  $lLasCondes,   60, 40000, $wednesdays],
            $sDrenaje   => [$pClaudia, $lProvidencia, 90, 45000, $saturdays],
        ];

        // Track date index per service so sessions from different packs
        // don't pile up on the same date
        $dateIdx = [$sRelajante => 0, $sKinesio => 0, $sDrenaje => 0];

        // Load all pending sessions grouped by pack, ordered by session_number.
        // For each pack we only schedule the first half — the rest stay pending
        // to reflect the real scenario where a client hasn't booked all sessions yet.
        $pending = DB::table('pack_sessions')
            ->join('client_packs', 'pack_sessions.client_pack_id', '=', 'client_packs.id')
            ->join('service_packs', 'client_packs.service_pack_id', '=', 'service_packs.id')
            ->where('pack_sessions.status', 'pending')
            ->select(
                'pack_sessions.id as session_id',
                'pack_sessions.session_number',
                'pack_sessions.client_pack_id',
                'client_packs.client_id',
                'service_packs.service_id'
            )
            ->orderBy('pack_sessions.client_pack_id')
            ->orderBy('pack_sessions.session_number')
            ->get()
            ->groupBy('client_pack_id');

        // Per pack: schedule only ceil(count / 2) sessions, leave the rest pending
        $toSchedule = collect();
        foreach ($pending as $packSessions) {
            $scheduleCount = (int) ceil($packSessions->count() / 2);
            $toSchedule = $toSchedule->concat($packSessions->take($scheduleCount));
        }

        foreach ($toSchedule as $session) {
            $svcId = $session->service_id;

            if (! isset($config[$svcId])) continue;

            [$providerId, $locationId, $duration, $price, $dates] = $config[$svcId];

            $idx = $dateIdx[$svcId];

            if ($idx >= count($dates)) continue;

            $date      = $dates[$idx];
            $startTime = "{$date} 12:00:00";
            $endTime   = date('Y-m-d H:i:s', strtotime($startTime) + $duration * 60);

            $bookingId = DB::table('bookings')->insertGetId([
                'client_id'   => $session->client_id,
                'service_id'  => $svcId,
                'provider_id' => $providerId,
                'location_id' => $locationId,
                'status_id'   => $statusReservado,
                'start_time'  => $startTime,
                'end_time'    => $endTime,
                'price'       => $price,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);

            DB::table('pack_sessions')
                ->where('id', $session->session_id)
                ->update([
                    'booking_id' => $bookingId,
                    'status'     => 'scheduled',
                    'updated_at' => now(),
                ]);

            $dateIdx[$svcId]++;
        }
    }
}
