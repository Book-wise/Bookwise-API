<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class JuneBookingsSeeder extends Seeder
{
    public function run(): void
    {
        $providers = DB::table('providers')->pluck('id', 'email');
        $services = DB::table('services')->pluck('id', 'name');
        $locations = DB::table('locations')->pluck('id', 'name');
        $clients = DB::table('clients')->orderBy('id')->pluck('id')->values()->all();

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

        // [start_time, provider_key, service_key]
        // Slots per provider within a day never overlap — verified manually.
        $patterns = [
            1 => [ // Monday
                ['09:00', 'maria',  'relajante'],   // ends 10:00
                ['10:15', 'maria',  'drenaje'],     // ends 11:45
                ['09:00', 'carlos', 'deportivo'],   // ends 09:45
                ['10:30', 'carlos', 'kinesio'],     // ends 11:30
                ['14:00', 'ana',    'kinesio'],     // ends 15:00
                ['15:15', 'ana',    'drenaje'],     // ends 16:45
            ],
            2 => [ // Tuesday
                ['09:00', 'jorge',   'relajante'],  // ends 10:00
                ['10:15', 'jorge',   'kinesio'],    // ends 11:15
                ['09:00', 'pilar',   'drenaje'],    // ends 10:30
                ['11:00', 'pilar',   'relajante'],  // ends 12:00
                ['14:00', 'diego',   'kinesio'],    // ends 15:00
                ['15:30', 'claudia', 'drenaje'],    // ends 17:00
            ],
            3 => [ // Wednesday
                ['09:00', 'carmen', 'deportivo'],   // ends 09:45
                ['10:00', 'carmen', 'kinesio'],     // ends 11:00
                ['09:00', 'sebas',  'kinesio'],     // ends 10:00
                ['10:30', 'sebas',  'deportivo'],   // ends 11:15
                ['14:00', 'diego',  'relajante'],   // ends 15:00
                ['15:30', 'claudia', 'relajante'],   // ends 16:30
            ],
            4 => [ // Thursday
                ['09:00', 'maria',  'relajante'],   // ends 10:00
                ['10:30', 'jorge',  'drenaje'],     // ends 12:00
                ['09:00', 'pilar',  'relajante'],   // ends 10:00
                ['10:30', 'carlos', 'kinesio'],     // ends 11:30
                ['14:00', 'ana',    'drenaje'],     // ends 15:30
                ['16:00', 'ana',    'kinesio'],     // ends 17:00
            ],
            5 => [ // Friday
                ['09:00', 'carmen', 'kinesio'],     // ends 10:00
                ['10:15', 'carmen', 'deportivo'],   // ends 11:00
                ['09:00', 'sebas',  'deportivo'],   // ends 09:45
                ['10:00', 'sebas',  'kinesio'],     // ends 11:00
                ['14:00', 'jorge',  'relajante'],   // ends 15:00
                ['15:30', 'diego',  'deportivo'],   // ends 16:15
            ],
            6 => [ // Saturday (reduced)
                ['10:00', 'maria',  'relajante'],   // ends 11:00
                ['11:30', 'maria',  'drenaje'],     // ends 13:00
                ['10:00', 'carlos', 'deportivo'],   // ends 10:45
                ['11:00', 'carlos', 'kinesio'],     // ends 12:00
                ['10:00', 'ana',    'kinesio'],     // ends 11:00
                ['14:00', 'pilar',  'relajante'],   // ends 15:00
            ],
            7 => [ // Sunday (minimal)
                ['10:00', 'maria',  'relajante'],   // ends 11:00
                ['11:30', 'diego',  'kinesio'],     // ends 12:30
                ['14:00', 'carlos', 'deportivo'],   // ends 14:45
                ['15:00', 'claudia', 'drenaje'],     // ends 16:30
            ],
        ];

        // Future bookings: statuses lean towards Reservado/Confirmado/Pendiente
        // status: 1=Reservado 2=Confirmado 5=Pendiente 6=EnEspera 7=Cancelada
        $statusCycle = [1, 2, 1, 2, 1, 5, 2, 1, 6, 1, 2, 7, 1, 2, 1, 2, 5, 1, 2, 1];
        $paymentCycle = [null, null, 'partial', null, 'paid', null, null, 'partial', null, null, null, 'partial'];

        $clientIdx = 0;
        $statusIdx = 0;
        $paymentIdx = 0;

        for ($day = 1; $day <= 30; $day++) {
            $date = sprintf('2026-06-%02d', $day);
            $dow = (int) date('N', strtotime($date)); // 1=Mon … 7=Sun
            $slots = $patterns[$dow] ?? [];

            foreach ($slots as [$time, $provKey, $svcKey]) {
                $startStr = "{$date} {$time}:00";
                $endStr = date('Y-m-d H:i:s', strtotime($startStr) + $serviceDuration[$svcKey] * 60);

                $clientId = $clients[$clientIdx % count($clients)];
                $statusId = $statusCycle[$statusIdx % count($statusCycle)];
                $payment = $paymentCycle[$paymentIdx % count($paymentCycle)];
                $price = $servicePrice[$svcKey];

                $clientIdx++;
                $statusIdx++;
                $paymentIdx++;

                $bookingId = DB::table('bookings')->insertGetId([
                    'client_id' => $clientId,
                    'service_id' => $s[$svcKey],
                    'provider_id' => $p[$provKey],
                    'location_id' => $l[$providerLocation[$provKey]],
                    'status_id' => $statusId,
                    'start_time' => $startStr,
                    'end_time' => $endStr,
                    'price' => $price,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($payment !== null) {
                    DB::table('sales')->insert([
                        'booking_id' => $bookingId,
                        'total' => $price,
                        'paid_amount' => match ($payment) {
                            'paid' => $price,
                            'partial' => (int) round($price * 0.5),
                            default => 0,
                        },
                        'payment_method' => 'transferencia',
                        'paid_at' => $payment === 'paid' ? now()->subDays(rand(1, 5)) : null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }
}
