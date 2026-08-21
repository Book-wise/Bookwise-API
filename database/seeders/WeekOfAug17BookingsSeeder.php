<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WeekOfAug17BookingsSeeder extends Seeder
{
    public function run(): void
    {
        // Helper idempotente: si la fila existe (según la clave natural $key)
        // la actualiza; si no, la inserta. Devuelve el id de la fila.
        // Permite correr el seeder varias veces sin duplicar registros.
        $upsert = function (string $table, array $key, array $values): int {
            if (DB::table($table)->where($key)->exists()) {
                DB::table($table)->where($key)->update(array_merge($values, ['updated_at' => now()]));
            } else {
                DB::table($table)->insert(array_merge($key, $values, ['created_at' => now(), 'updated_at' => now()]));
            }

            return DB::table($table)->where($key)->value('id');
        };

        $providers = DB::table('providers')->pluck('id', 'email');
        $services = DB::table('services')->pluck('id', 'name');
        $locations = DB::table('locations')->pluck('id', 'name');
        // Pool estable: excluye al cliente demo (HistoricsDemoSeeder) para que la
        // rotación % count no cambie entre corridas ni se borre nada por su cleanup.
        $clientsById = DB::table('clients')
            ->where('email', '!=', 'demo.historics@mail.com')
            ->orderBy('id')
            ->pluck('id');
        $clients = $clientsById->values()->all();
        $emailToClient = DB::table('clients')->pluck('id', 'email');

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
            'centro' => $locations['Kinesilk Centro'],
            'lascondes' => $locations['Kinesilk Las Condes'],
            'providencia' => $locations['Kinesilk Providencia'],
        ];

        $providerLocation = [
            'maria' => 'centro',
            'carmen' => 'centro',
            'jorge' => 'centro',
            'carlos' => 'lascondes',
            'pilar' => 'lascondes',
            'sebas' => 'lascondes',
            'ana' => 'providencia',
            'diego' => 'providencia',
            'claudia' => 'providencia',
        ];

        $serviceDuration = [
            'relajante' => 60,
            'deportivo' => 45,
            'kinesio' => 60,
            'drenaje' => 90,
        ];

        $servicePrice = [
            'relajante' => 35000,
            'deportivo' => 28000,
            'kinesio' => 40000,
            'drenaje' => 45000,
        ];

        // Slots por provider dentro de un mismo día nunca se solapan — verificado manualmente.
        $patterns = [
            1 => [ // Monday 2026-08-17
                ['09:00', 'maria',  'relajante'],   // ends 10:00
                ['10:15', 'maria',  'drenaje'],     // ends 11:45
                ['09:00', 'carlos', 'deportivo'],   // ends 09:45
                ['10:30', 'carlos', 'kinesio'],     // ends 11:30
                ['14:00', 'ana',    'kinesio'],     // ends 15:00
                ['15:15', 'ana',    'drenaje'],     // ends 16:45
                ['16:00', 'jorge',  'relajante'],   // ends 17:00
                ['11:00', 'sebas',  'deportivo'],   // ends 11:45
            ],
            2 => [ // Tuesday 2026-08-18
                ['09:00', 'jorge',   'relajante'],  // ends 10:00
                ['10:15', 'jorge',   'kinesio'],    // ends 11:15
                ['09:00', 'pilar',   'drenaje'],    // ends 10:30
                ['11:00', 'pilar',   'relajante'],  // ends 12:00
                ['14:00', 'diego',   'kinesio'],    // ends 15:00
                ['15:30', 'claudia', 'drenaje'],    // ends 17:00
                ['16:30', 'maria',   'relajante'],  // ends 17:30
                ['10:00', 'carlos',  'kinesio'],    // ends 11:00
            ],
            3 => [ // Wednesday 2026-08-19
                ['09:00', 'carmen', 'deportivo'],   // ends 09:45
                ['10:00', 'carmen', 'kinesio'],     // ends 11:00
                ['09:00', 'sebas',  'kinesio'],     // ends 10:00
                ['10:30', 'sebas',  'deportivo'],   // ends 11:15
                ['14:00', 'diego',  'relajante'],   // ends 15:00
                ['15:30', 'claudia', 'relajante'],  // ends 16:30
                ['11:30', 'pilar',  'drenaje'],     // ends 13:00
                ['16:00', 'ana',    'kinesio'],     // ends 17:00
            ],
            4 => [ // Thursday 2026-08-20
                ['09:00', 'maria',  'relajante'],   // ends 10:00
                ['10:30', 'jorge',  'drenaje'],     // ends 12:00
                ['09:00', 'pilar',  'relajante'],   // ends 10:00
                ['10:30', 'carlos', 'kinesio'],     // ends 11:30
                ['14:00', 'ana',    'drenaje'],     // ends 15:30
                ['16:00', 'ana',    'kinesio'],     // ends 17:00
                ['11:30', 'claudia', 'drenaje'],    // ends 13:00
                ['15:30', 'diego',  'deportivo'],   // ends 16:15
            ],
            5 => [ // Friday 2026-08-21
                ['09:00', 'carmen', 'kinesio'],     // ends 10:00
                ['10:15', 'carmen', 'deportivo'],   // ends 11:00
                ['09:00', 'sebas',  'deportivo'],   // ends 09:45
                ['10:00', 'sebas',  'kinesio'],     // ends 11:00
                ['14:00', 'jorge',  'relajante'],   // ends 15:00
                ['15:30', 'diego',  'deportivo'],   // ends 16:15
                ['11:00', 'maria',  'drenaje'],     // ends 12:30
                ['16:30', 'carlos', 'kinesio'],     // ends 17:30
            ],
            6 => [ // Saturday 2026-08-22
                ['10:00', 'maria',  'relajante'],   // ends 11:00
                ['11:30', 'maria',  'drenaje'],     // ends 13:00
                ['10:00', 'carlos', 'deportivo'],   // ends 10:45
                ['11:00', 'carlos', 'kinesio'],     // ends 12:00
                ['10:00', 'ana',    'kinesio'],     // ends 11:00
                ['14:00', 'pilar',  'relajante'],   // ends 15:00
            ],
            7 => [ // Sunday 2026-08-23
                ['10:00', 'maria',  'relajante'],   // ends 11:00
                ['11:30', 'diego',  'kinesio'],     // ends 12:30
                ['14:00', 'carlos', 'deportivo'],   // ends 14:45
                ['15:00', 'claudia', 'drenaje'],    // ends 16:30
                ['16:00', 'pilar',   'relajante'],  // ends 17:00
            ],
        ];

        // Pasados (lun 17, mar 18): terminales (3 Asiste, 4 No asistio) + 7 Cancelada + algunos 2 Confirmado.
        // Futuros (mié 19 .. dom 23): 1 Reservado, 2 Confirmado, 5 Pendiente, 6 En espera + un par de 7 Cancelada.
        $statusByDate = [
            '2026-08-17' => [3, 3, 4, 2, 3, 4, 7, 2],
            '2026-08-18' => [3, 4, 3, 2, 2, 7, 4, 3],
            '2026-08-19' => [2, 1, 5, 2, 6, 1, 2, 5],
            '2026-08-20' => [1, 2, 5, 2, 7, 6, 2, 5],
            '2026-08-21' => [2, 7, 1, 5, 6, 1, 2, 5],
            '2026-08-22' => [2, 1, 5, 2, 6, 1],
            '2026-08-23' => [2, 1, 5, 6, 2],
        ];

        // payment: null = sin sale | 'paid' = total | 'partial_50' = abono 50% | 'partial_30' = anticipo 30% | 'unpaid' = sale sin pago
        $paymentByDate = [
            '2026-08-17' => ['paid', 'paid', 'unpaid', 'partial_50', 'paid', null, null, 'paid'],
            '2026-08-18' => ['paid', 'unpaid', 'partial_50', 'paid', 'paid', null, null, 'paid'],
            '2026-08-19' => ['partial_30', 'paid', 'unpaid', 'partial_30', 'partial_50', null, null, 'paid'],
            '2026-08-20' => [null, 'partial_30', 'paid', 'partial_50', 'partial_30', 'unpaid', null, null],
            '2026-08-21' => ['paid', 'partial_30', 'partial_50', 'unpaid', null, 'partial_30', 'paid', null],
            '2026-08-22' => [null, 'partial_30', 'paid', 'unpaid', 'partial_50', null],
            '2026-08-23' => ['paid', 'partial_30', 'partial_50', null, 'paid'],
        ];

        // Notas para variedad: alergia (Andrés) y preferencia de horario (Pedro, Isadora).
        $notesByClient = [
            $emailToClient['andres@mail.com'] => 'Alergia a aceites esenciales — usar aceite neutro',
            $emailToClient['pedro@mail.com'] => 'Prefiere horarios temprano en la mañana',
            $emailToClient['isadora@mail.com'] => 'Prefiere intensidad suave en el masaje',
        ];

        $methods = ['transferencia', 'efectivo', 'tarjeta'];
        $methodIdx = 0;

        $pastDates = ['2026-08-17', '2026-08-18'];
        $clientIdx = 0;

        foreach ($statusByDate as $date => $statuses) {
            $dow = (int) date('N', strtotime($date));
            $slots = $patterns[$dow] ?? [];

            foreach ($slots as $i => [$time, $provKey, $svcKey]) {
                $startStr = "{$date} {$time}:00";
                $endStr = date('Y-m-d H:i:s', strtotime($startStr) + $serviceDuration[$svcKey] * 60);
                $price = $servicePrice[$svcKey];
                $statusId = $statuses[$i] ?? 1;
                $payment = $paymentByDate[$date][$i] ?? null;
                $clientId = $clients[$clientIdx % count($clients)];
                $clientIdx++;

                $bookingId = $upsert('bookings', [
                    'provider_id' => $p[$provKey],
                    'location_id' => $l[$providerLocation[$provKey]],
                    'start_time' => $startStr,
                ], [
                    'client_id' => $clientId,
                    'service_id' => $s[$svcKey],
                    'status_id' => $statusId,
                    'end_time' => $endStr,
                    'price' => $price,
                    'notes' => $notesByClient[$clientId] ?? null,
                    'created_via' => 'admin_calendar',
                    'last_modified_via' => 'admin_calendar',
                ]);

                if ($payment === null) {
                    continue;
                }

                $method = $methods[$methodIdx++ % count($methods)];

                // paid_at realista: días pasados para pagos de días pasados,
                // fechas previas a la semana para anticipos/abonos de reservas futuras.
                $paidAt = null;
                if (in_array($payment, ['paid', 'partial_50', 'partial_30'])) {
                    if ($payment === 'partial_30') {
                        $paidAt = date('Y-m-d H:i:s', strtotime('2026-08-15 09:00:00') + $i * 3600);
                    } elseif (in_array($date, $pastDates)) {
                        $daysBefore = $payment === 'paid' ? 1 + ($i % 3) : 2;
                        $paidAt = date('Y-m-d H:i:s', strtotime($startStr) - $daysBefore * 86400);
                    } else {
                        $paidAt = date('Y-m-d H:i:s', strtotime('2026-08-14 18:00:00') + $i * 3600);
                    }
                }

                $paidAmount = match ($payment) {
                    'paid' => $price,
                    'partial_50' => (int) round($price * 0.5),
                    'partial_30' => (int) round($price * 0.3),
                    default => 0,
                };

                $saleId = $upsert('sales', ['booking_id' => $bookingId], [
                    'client_id' => $clientId,
                    'client_pack_id' => null,
                    'total' => $price,
                    'paid_amount' => $paidAmount,
                    'payment_method' => $payment === 'unpaid' ? null : $method,
                    'paid_at' => $paidAt,
                ]);

                if ($payment === 'unpaid') {
                    continue;
                }

                $notes = match ($payment) {
                    'paid' => 'Pago total de la sesión',
                    'partial_50' => 'Abono inicial (50%)',
                    'partial_30' => 'Anticipo de reserva (30%)',
                    default => 'Pago',
                };

                $upsert('sale_transactions', [
                    'sale_id' => $saleId,
                    'amount' => $paidAmount,
                    'notes' => $notes,
                ], [
                    'payment_method' => $method,
                    'paid_at' => $paidAt,
                ]);
            }
        }
    }
}
