<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Schedules future June bookings for pending pack sessions so every sale
 * linked to a client_pack returns complete session data in GET /sales/:id.
 *
 * Strategy: all pack sessions use the 12:00 slot — verified free across all
 * providers in June (JuneBookingsSeeder only occupies 09:00–11:xx and 14:00+).
 *
 * Provider assignment per service type (providers free at 12:00 in June):
 *   svc1 Relajante (60min) → Pilar p5 loc2  — Tuesdays
 *   svc3 Kinesio   (60min) → Carlos p4 loc2 — Wednesdays
 *   svc4 Drenaje   (90min) → Claudia p9 loc3 — Saturdays
 *
 * Idempotent: cada sesión pendiente se mapea a una fecha según su posición
 * GLOBAL estable (ordenando TODAS las sesiones del servicio por client_pack_id
 * + session_number, sin importar su estado). Así una sesión pendiente obtiene
 * la MISMÍSIMA fecha en cada corrida y el upsert no duplica filas.
 */
class PackSessionsScheduleSeeder extends Seeder
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

        $providers = DB::table('providers')->pluck('id', 'email');
        $locations = DB::table('locations')->pluck('id', 'name');
        $services = DB::table('services')->pluck('id', 'name');
        $statuses = DB::table('booking_statuses')->pluck('id', 'name');

        $pPilar = $providers['pilar@kinesilk.cl'];
        $pCarlos = $providers['carlos@kinesilk.cl'];
        $pClaudia = $providers['claudia@kinesilk.cl'];

        $lLasCondes = $locations['Kinesilk Las Condes'];
        $lProvidencia = $locations['Kinesilk Providencia'];

        $sRelajante = $services['Masaje Relajante'];
        $sKinesio = $services['Kinesiología'];
        $sDrenaje = $services['Drenaje Linfático'];

        $statusReservado = $statuses['Reservado'] ?? 1;

        // Tuesdays in June 2026 (for Relajante)
        $tuesdays = ['2026-06-02', '2026-06-09', '2026-06-16', '2026-06-23', '2026-06-30'];
        // Wednesdays in June 2026 (for Kinesio)
        $wednesdays = ['2026-06-03', '2026-06-10', '2026-06-17', '2026-06-24'];
        // Saturdays in June 2026 (for Drenaje)
        $saturdays = ['2026-06-06', '2026-06-13', '2026-06-20', '2026-06-27'];

        // Service config: [service_id, provider_id, location_id, duration_min, price, dates_pool]
        $config = [
            $sRelajante => [$pPilar,   $lLasCondes,   60, 35000, $tuesdays],
            $sKinesio => [$pCarlos,  $lLasCondes,   60, 40000, $wednesdays],
            $sDrenaje => [$pClaudia, $lProvidencia, 90, 45000, $saturdays],
        ];

        // Todas las sesiones del servicio — el ORDEN GLOBAL es estable entre
        // corridas (las filas se crean de forma idempotente), así la posición
        // de cada sesión pendiente no cambia y la fecha asignada tampoco.
        $sessions = DB::table('pack_sessions')
            ->join('client_packs', 'pack_sessions.client_pack_id', '=', 'client_packs.id')
            ->join('service_packs', 'client_packs.service_pack_id', '=', 'service_packs.id')
            ->select(
                'pack_sessions.id as session_id',
                'pack_sessions.session_number',
                'pack_sessions.client_pack_id',
                'pack_sessions.status as session_status',
                'client_packs.client_id',
                'service_packs.service_id'
            )
            ->orderBy('pack_sessions.client_pack_id')
            ->orderBy('pack_sessions.session_number')
            ->get();

        // Sesiones por servicio manteniendo el orden estable.
        $byService = $sessions->groupBy('service_id');

        foreach ($byService as $svcId => $serviceSessions) {
            if (! isset($config[$svcId])) {
                continue;
            }

            [$providerId, $locationId, $duration, $price, $dates] = $config[$svcId];

            foreach ($serviceSessions as $position => $session) {
                // Solo se agendan pendientes; queremos mantener su posición.
                if ($session->session_status !== 'pending') {
                    continue;
                }

                // Índice de fecha derivado de la posición global estable.
                if ($position >= count($dates)) {
                    continue;
                }

                $date = $dates[$position];
                $startTime = "{$date} 12:00:00";
                $endTime = date('Y-m-d H:i:s', strtotime($startTime) + $duration * 60);

                // Guarda: no pisar un slot ya ocupado del mismo provider.
                $slotTaken = DB::table('bookings')
                    ->where('provider_id', $providerId)
                    ->where('start_time', $startTime)
                    ->exists();

                if ($slotTaken) {
                    continue;
                }

                $bookingId = $upsert('bookings', [
                    'provider_id' => $providerId,
                    'location_id' => $locationId,
                    'start_time' => $startTime,
                ], [
                    'client_id' => $session->client_id,
                    'service_id' => $svcId,
                    'status_id' => $statusReservado,
                    'end_time' => $endTime,
                    'price' => $price,
                ]);

                DB::table('pack_sessions')
                    ->where('id', $session->session_id)
                    ->update([
                        'booking_id' => $bookingId,
                        'status' => 'scheduled',
                        'updated_at' => now(),
                    ]);
            }
        }
    }
}
