<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Genera bookings de la SEMANA ACTUAL (lunes→domingo) con escenarios variables:
 * asistencia + pago total/parcial, inasistencia (con y sin venta), canceladas
 * (con venta parcial y sin pago), reservas/pendientes futuras sin venta, y
 * sesiones vinculadas a packs en los días pasados.
 *
 * Requiere correr después de TestDataSeeder (providers/services/locations/
 * clients/statuses) — DatabaseSeeder garantiza el orden.
 *
 * Totalmente idempotente: bookings por (provider_id, location_id, start_time);
 * sales por booking_id/client_pack_id; client_packs por
 * (client_id, service_pack_id); pack_sessions por (client_pack_id,
 * session_number). Los contadores (used_sessions) se fijan de forma
 * determinística (nunca += ).
 */
class WeeklyScenariosSeeder extends Seeder
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

        // status: 1=Reservado 2=Confirmado 3=Asiste 4=No asistio
        //         5=Pendiente 6=En espera 7=Cancelada

        // ── Referencias existentes (gestionadas por seeders anteriores) ──
        $providers = DB::table('providers')->pluck('id', 'email');
        $services = DB::table('services')->pluck('id', 'name');
        $locations = DB::table('locations')->pluck('id', 'name');
        // Pool estable: excluye al cliente demo (HistoricsDemoSeeder) para que los
        // índices/rotación de clientes no cambien entre corridas ni se borre nada
        // por su cleanup.
        $clients = DB::table('clients')
            ->where('email', '!=', 'demo.historics@mail.com')
            ->orderBy('id')
            ->pluck('id')
            ->values()
            ->all();
        $packs = DB::table('service_packs')->pluck('id', 'name');
        $durations = DB::table('services')->pluck('duration_minutes', 'id');
        $prices = DB::table('services')->pluck('price', 'id');

        $p = [
            'maria' => $providers['maria@kinesilk.cl'],
            'carmen' => $providers['carmen@kinesilk.cl'],
            'jorge' => $providers['jorge@kinesilk.cl'],
            'carlos' => $providers['carlos@kinesilk.cl'],
            'pilar' => $providers['pilar@kinesilk.cl'],
            'sebas' => $providers['sebastian@kinesilk.cl'],
            'ana' => $providers['ana@kinesilk.cl'],
            'diego' => $providers['diego@kinesilk.cl'],
            'claudia' => $providers['claudia@kinesilk.cl'],
        ];

        $s = [
            'relajante' => $services['Masaje Relajante'],
            'deportivo' => $services['Masaje Deportivo'],
            'kinesio' => $services['Kinesiología'],
            'drenaje' => $services['Drenaje Linfático'],
        ];

        $l = [
            'maria' => $locations['Kinesilk Centro'],
            'carmen' => $locations['Kinesilk Centro'],
            'jorge' => $locations['Kinesilk Centro'],
            'carlos' => $locations['Kinesilk Las Condes'],
            'pilar' => $locations['Kinesilk Las Condes'],
            'sebas' => $locations['Kinesilk Las Condes'],
            'ana' => $locations['Kinesilk Providencia'],
            'diego' => $locations['Kinesilk Providencia'],
            'claudia' => $locations['Kinesilk Providencia'],
        ];

        $weekStart = Carbon::now()->startOfWeek(); // Lunes 00:00
        $today = Carbon::now()->toDateString();

        // Escenarios por día (offset 0=Lunes … 6=Domingo).
        // [hora, provider, servicio, cliente(idx), escenario, tipoVenta]
        // - attended   → pasado: 3 (Asiste); hoy/futuro: 2 (Confirmado)
        // - no_show    → pasado: 4 (No asistio); hoy/futuro: 1 (Reservado)
        // - cancelled  → pasado: 7 (Cancelada); hoy/futuro: 2 (Confirmado)
        // - reserved/pending/confirmed/waiting → siempre no terminal
        //
        // NOTA: los slots NO se solapan con WeekOfAug17BookingsSeeder (misma
        // semana) — elegimos providers/horarios libres para cada día.
        $slots = [
            0 => [ // Lunes — escenarios terminales (según qué días ya pasaron)
                ['09:00', 'carmen', 'relajante', 1, 'attended',   'pack1_full'],            // Pedro · pack relajante
                ['10:15', 'carmen', 'drenaje',   0, 'no_show',    'unpaid_sale'],
                ['09:00', 'pilar',  'deportivo', 2, 'cancelled',  'partial_efectivo'],
                ['10:30', 'pilar',  'kinesio',   3, 'attended',   'pack2_full_efectivo'],   // Andrés · pack kinesio
                ['11:00', 'diego',  'kinesio',   8, 'no_show',    'pack3_unpaid'],          // Isadora · sin venta
                ['12:15', 'diego',  'relajante', 5, 'cancelled',  'pack4_unpaid'],          // Rodrigo · sin venta
                ['14:00', 'claudia', 'drenaje',  6, 'attended',   'partial_sale'],
            ],
            1 => [ // Martes
                ['09:00', 'maria',  'relajante', 7, 'reserved',  null],
                ['10:15', 'carmen', 'drenaje',   4, 'pending',   null],
                ['13:00', 'carlos', 'kinesio',   9, 'confirmed', null],
                ['15:00', 'diego',  'deportivo', 0, 'waiting',   null],
            ],
            2 => [ // Miércoles
                ['09:00', 'maria',  'kinesio',   2, 'reserved',  null],
                ['10:15', 'jorge',  'relajante', 4, 'pending',   null],
                ['12:00', 'ana',    'drenaje',   3, 'confirmed', null],
                ['15:00', 'carmen', 'deportivo', 5, 'pending',   null],
            ],
            3 => [ // Jueves
                ['09:00', 'jorge',   'relajante', 6, 'reserved',  null],
                ['10:00', 'claudia', 'kinesio',   7, 'pending',   null],
                ['13:00', 'pilar',   'relajante', 8, 'confirmed', null],
                ['15:30', 'sebas',   'deportivo', 9, 'pending',   null],
            ],
            4 => [ // Viernes
                ['09:00', 'maria',  'relajante', 0, 'reserved', null],
                ['10:30', 'jorge',  'kinesio',   1, 'pending',  null],
                ['14:00', 'carlos', 'relajante', 2, 'waiting',  null],
                ['15:30', 'ana',    'deportivo', 3, 'reserved', null],
            ],
            5 => [ // Sábado
                ['10:00', 'jorge', 'relajante', 4, 'reserved',  null],
                ['11:30', 'pilar', 'drenaje',   5, 'pending',   null],
                ['14:00', 'jorge', 'kinesio',   6, 'confirmed', null],
            ],
            6 => [ // Domingo
                ['10:00', 'ana',    'kinesio',   7, 'reserved',  null],
                ['11:30', 'carlos', 'deportivo', 8, 'confirmed', null],
            ],
        ];

        // ── Crea los packs de la semana (clientes sin pack previo) ──
        // packKey => [clientIdx, nombre pack]. total_sessions se leen del schema.
        $packDefs = [
            'pack1' => [1, 'Pack Masaje Relajante x6'],
            'pack2' => [3, 'Pack Kinesiología x8'],
            'pack3' => [8, 'Pack Kinesiología x8'],
            'pack4' => [5, 'Pack Masaje Relajante x6'],
        ];

        $packInfo = []; // packKey => [cp_id, service_pack_id, service_id, total_sessions, client_id]
        foreach ($packDefs as $packKey => [$clientIdx, $packName]) {
            $servicePackId = $packs[$packName];
            $servicePack = DB::table('service_packs')->where('id', $servicePackId)->first();

            $cpId = $upsert('client_packs', [
                'client_id' => $clients[$clientIdx],
                'service_pack_id' => $servicePackId,
            ], [
                'total_sessions' => $servicePack->total_sessions,
                'used_sessions' => 0, // se corrige cuando se resuelve la sesión 1
                'status' => 'active',
            ]);

            // Sesiones pendientes 2..total (la sesión 1 se vincula al booking).
            foreach (range(2, $servicePack->total_sessions) as $n) {
                $upsert('pack_sessions', [
                    'client_pack_id' => $cpId,
                    'session_number' => $n,
                ], [
                    'booking_id' => null,
                    'status' => 'pending',
                    'attended_at' => null,
                ]);
            }

            $packInfo[$packKey] = [
                'cp_id' => $cpId,
                'service_pack_id' => $servicePackId,
                'service_id' => $servicePack->service_id,
                'total_sessions' => $servicePack->total_sessions,
                'client_id' => $clients[$clientIdx],
            ];
        }

        // ── Generate bookings per day ─────────────────────────────────
        foreach ($slots as $offset => $daySlots) {
            $date = (clone $weekStart)->addDays($offset);
            $isPast = $date->toDateString() < $today;

            foreach ($daySlots as [$time, $provKey, $svcKey, $clientIdx, $scenario, $saleType]) {
                $serviceId = $s[$svcKey];
                $startTime = "{$date->toDateString()} {$time}:00";
                $endTime = date('Y-m-d H:i:s', strtotime($startTime) + $durations[$serviceId] * 60);
                $price = (float) $prices[$serviceId];
                $clientId = $clients[$clientIdx];

                $statusId = match ($scenario) {
                    'attended' => $isPast ? 3 : 2,
                    'no_show' => $isPast ? 4 : 1,
                    'cancelled' => $isPast ? 7 : 2,
                    'reserved' => 1,
                    'confirmed' => 2,
                    'pending' => 5,
                    'waiting' => 6,
                };

                $bookingId = $upsert('bookings', [
                    'provider_id' => $p[$provKey],
                    'location_id' => $l[$provKey],
                    'start_time' => $startTime,
                ], [
                    'client_id' => $clientId,
                    'service_id' => $serviceId,
                    'status_id' => $statusId,
                    'end_time' => $endTime,
                    'price' => $price,
                    'created_via' => 'admin_calendar',
                    'last_modified_via' => 'admin_calendar',
                ]);

                // ── Vinculación de sesión de pack (día 1) ──────────
                if ($saleType !== null && str_starts_with($saleType, 'pack')) {
                    preg_match('/^pack(\d+)/', $saleType, $m);
                    $packKey = 'pack'.$m[1];
                    $info = $packInfo[$packKey];

                    $sessionStatus = match ($scenario) {
                        'attended' => $isPast ? 'attended' : 'scheduled',
                        default => $isPast ? 'cancelled' : 'scheduled',
                    };

                    $upsert('pack_sessions', [
                        'client_pack_id' => $info['cp_id'],
                        'session_number' => 1,
                    ], [
                        'booking_id' => $bookingId,
                        'status' => $sessionStatus,
                        'attended_at' => $sessionStatus === 'attended' ? $endTime : null,
                    ]);

                    // used_sessions determinístico: 1 si hubo asistencia, 0 si no.
                    DB::table('client_packs')->where('id', $info['cp_id'])->update([
                        'used_sessions' => $sessionStatus === 'attended' ? 1 : 0,
                        'updated_at' => now(),
                    ]);
                }

                // ── Ventas ──────────────────────────────────────────
                if ($saleType === null) {
                    continue;
                }

                if (str_starts_with($saleType, 'pack')) {
                    // Venta del PACK (cliente × pack), no del booking individual.
                    preg_match('/^pack(\d+)/', $saleType, $m);
                    $info = $packInfo['pack'.$m[1]];
                    $totalPack = (float) $prices[$info['service_id']] * $info['total_sessions'];
                    $isFull = str_contains($saleType, '_full');

                    $upsert('sales', ['client_pack_id' => $info['cp_id']], [
                        'client_id' => $info['client_id'],
                        'total' => $totalPack,
                        'paid_amount' => $isFull ? $totalPack : 0,
                        'payment_method' => $isFull
                            ? (str_contains($saleType, 'efectivo') ? 'efectivo' : 'transferencia')
                            : null,
                        'paid_at' => $isFull ? $startTime : null,
                    ]);
                } else {
                    // Venta del booking individual.
                    $paidAmount = in_array($saleType, ['partial_sale', 'partial_efectivo'], true)
                        ? (int) round($price * 0.5)
                        : 0;
                    $method = match ($saleType) {
                        'partial_efectivo' => 'efectivo',
                        'partial_sale' => 'transferencia',
                        default => null,
                    };
                    $isPaid = $paidAmount > 0;

                    $upsert('sales', ['booking_id' => $bookingId], [
                        'client_id' => $clientId,
                        'total' => $price,
                        'paid_amount' => $paidAmount,
                        'payment_method' => $method,
                        'paid_at' => $isPaid ? $startTime : null,
                    ]);
                }
            }
        }
    }
}
