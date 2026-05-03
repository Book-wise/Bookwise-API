<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class TestDataSeeder extends Seeder
{
    public function run(): void
    {
        // ── Locations ─────────────────────────────────────────────────
        $locCentro      = DB::table('locations')->insertGetId(['name' => 'Kinesilk Centro',      'address' => 'Av. Providencia 1234',     'city' => 'Santiago', 'timezone' => 'America/Santiago', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $locLasCondes   = DB::table('locations')->insertGetId(['name' => 'Kinesilk Las Condes',  'address' => 'Av. Apoquindo 4500',       'city' => 'Santiago', 'timezone' => 'America/Santiago', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $locProvidencia = DB::table('locations')->insertGetId(['name' => 'Kinesilk Providencia', 'address' => 'Av. Pedro de Valdivia 210','city' => 'Santiago', 'timezone' => 'America/Santiago', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);

        // ── Services ──────────────────────────────────────────────────
        $svcRelajante  = DB::table('services')->insertGetId(['name' => 'Masaje Relajante',  'duration_minutes' => 60, 'slot_interval_minutes' => 15, 'min_duration_minutes' => 30, 'max_duration_minutes' => 120, 'price' => 35000, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $svcDeportivo  = DB::table('services')->insertGetId(['name' => 'Masaje Deportivo',  'duration_minutes' => 45, 'slot_interval_minutes' => 15, 'min_duration_minutes' => 30, 'max_duration_minutes' => 90,  'price' => 28000, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $svcKinesio    = DB::table('services')->insertGetId(['name' => 'Kinesiología',      'duration_minutes' => 60, 'slot_interval_minutes' => 15, 'min_duration_minutes' => 45, 'max_duration_minutes' => 120, 'price' => 40000, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $svcDrenaje    = DB::table('services')->insertGetId(['name' => 'Drenaje Linfático', 'duration_minutes' => 90, 'slot_interval_minutes' => 15, 'min_duration_minutes' => 60, 'max_duration_minutes' => 120, 'price' => 45000, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);

        // ── Providers ─────────────────────────────────────────────────
        $pMaria  = DB::table('providers')->insertGetId(['first_name' => 'María',  'last_name' => 'González', 'email' => 'maria@kinesilk.cl',  'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $pCarlos = DB::table('providers')->insertGetId(['first_name' => 'Carlos', 'last_name' => 'Rojas',    'email' => 'carlos@kinesilk.cl', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $pAna    = DB::table('providers')->insertGetId(['first_name' => 'Ana',    'last_name' => 'Fernández','email' => 'ana@kinesilk.cl',    'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $pDiego  = DB::table('providers')->insertGetId(['first_name' => 'Diego',  'last_name' => 'Morales',  'email' => 'diego@kinesilk.cl',  'active' => true, 'created_at' => now(), 'updated_at' => now()]);

        // ── Provider ↔ Location ───────────────────────────────────────
        DB::table('location_provider')->insert([
            ['location_id' => $locCentro,      'provider_id' => $pMaria],
            ['location_id' => $locLasCondes,   'provider_id' => $pMaria],
            ['location_id' => $locCentro,      'provider_id' => $pCarlos],
            ['location_id' => $locLasCondes,   'provider_id' => $pCarlos],
            ['location_id' => $locLasCondes,   'provider_id' => $pAna],
            ['location_id' => $locProvidencia, 'provider_id' => $pAna],
            ['location_id' => $locCentro,      'provider_id' => $pDiego],
            ['location_id' => $locProvidencia, 'provider_id' => $pDiego],
        ]);

        // ── Provider ↔ Service ────────────────────────────────────────
        DB::table('provider_service')->insert([
            ['provider_id' => $pMaria,  'service_id' => $svcRelajante],
            ['provider_id' => $pMaria,  'service_id' => $svcDrenaje],
            ['provider_id' => $pCarlos, 'service_id' => $svcDeportivo],
            ['provider_id' => $pCarlos, 'service_id' => $svcKinesio],
            ['provider_id' => $pAna,    'service_id' => $svcRelajante],
            ['provider_id' => $pAna,    'service_id' => $svcKinesio],
            ['provider_id' => $pAna,    'service_id' => $svcDrenaje],
            ['provider_id' => $pDiego,  'service_id' => $svcKinesio],
            ['provider_id' => $pDiego,  'service_id' => $svcDeportivo],
            ['provider_id' => $pDiego,  'service_id' => $svcRelajante],
        ]);

        // ── Booking statuses — IDs fijos para coincidir con el front ──
        DB::table('booking_statuses')->insert([
            ['id' => 1, 'name' => 'Reservado',  'color' => '#93c5fd', 'is_cancellation' => false, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Confirmado', 'color' => '#fb923c', 'is_cancellation' => false, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'name' => 'Asiste',     'color' => '#ec4899', 'is_cancellation' => false, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 4, 'name' => 'No asistio', 'color' => '#f9a8d4', 'is_cancellation' => false, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 5, 'name' => 'Pendiente',  'color' => '#fca5a5', 'is_cancellation' => false, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 6, 'name' => 'En espera',  'color' => '#86efac', 'is_cancellation' => false, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 7, 'name' => 'Cancelada',  'color' => '#d1d5db', 'is_cancellation' => true,  'created_at' => now(), 'updated_at' => now()],
        ]);

        // ── Clients ───────────────────────────────────────────────────
        $c = [];
        foreach ([
            ['Laura',     'Martínez', 'laura@mail.com',   'female'],
            ['Pedro',     'Soto',     'pedro@mail.com',   'male'],
            ['Valentina', 'López',    'vale@mail.com',    'female'],
            ['Andrés',    'García',   'andres@mail.com',  'male'],
            ['Camila',    'Torres',   'camila@mail.com',  'female'],
            ['Rodrigo',   'Vega',     'rodrigo@mail.com', 'male'],
            ['Sofía',     'Herrera',  'sofia@mail.com',   'female'],
            ['Matías',    'Díaz',     'matias@mail.com',  'male'],
            ['Isadora',   'Muñoz',    'isadora@mail.com', 'female'],
            ['Felipe',    'Castro',   'felipe@mail.com',  'male'],
        ] as [$fn, $ln, $email, $gender]) {
            $c[] = DB::table('clients')->insertGetId([
                'first_name' => $fn, 'last_name' => $ln,
                'email' => $email,   'gender'    => $gender,
                'active' => true,    'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // ── Users ─────────────────────────────────────────────────────
        DB::table('users')->insert([
            ['name' => 'Admin Kinesilk', 'email' => 'admin@kinesilk.cl',  'password' => Hash::make('password'), 'role' => 'admin',    'provider_id' => null,    'created_at' => now(), 'updated_at' => now()],
            ['name' => 'María González', 'email' => 'maria@kinesilk.cl',  'password' => Hash::make('password'), 'role' => 'provider', 'provider_id' => $pMaria, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Carlos Rojas',   'email' => 'carlos@kinesilk.cl', 'password' => Hash::make('password'), 'role' => 'provider', 'provider_id' => $pCarlos,'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Ana Fernández',  'email' => 'ana@kinesilk.cl',    'password' => Hash::make('password'), 'role' => 'provider', 'provider_id' => $pAna,   'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Diego Morales',  'email' => 'diego@kinesilk.cl',  'password' => Hash::make('password'), 'role' => 'provider', 'provider_id' => $pDiego, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // ── Bookings semana 27 abr – 3 may 2026 ──────────────────────
        // Columnas: [start, end, client, service, provider, location, status, price, payment]
        // payment: null=sin sale | 'paid'=pagado total | 'partial'=50% | 'unpaid'=sale sin pago

        $bookings = [
            // Lunes 27 abr
            ['2026-04-27 09:00', '2026-04-27 10:00', $c[0], $svcRelajante, $pMaria,  $locCentro,      2, 35000, 'paid'],
            ['2026-04-27 10:15', '2026-04-27 11:15', $c[1], $svcRelajante, $pMaria,  $locCentro,      3, 35000, 'paid'],
            ['2026-04-27 11:30', '2026-04-27 12:15', $c[2], $svcDeportivo, $pCarlos, $locLasCondes,   2, 28000, 'partial'],
            ['2026-04-27 14:00', '2026-04-27 15:00', $c[3], $svcKinesio,   $pAna,    $locProvidencia, 1, 40000, null],
            ['2026-04-27 15:30', '2026-04-27 17:00', $c[4], $svcDrenaje,   $pDiego,  $locCentro,      5, 45000, null],

            // Martes 28 abr
            ['2026-04-28 09:00', '2026-04-28 10:30', $c[4], $svcDrenaje,   $pMaria,  $locCentro,      3, 45000, 'paid'],
            ['2026-04-28 10:30', '2026-04-28 11:15', $c[5], $svcDeportivo, $pCarlos, $locLasCondes,   2, 28000, 'partial'],
            ['2026-04-28 14:00', '2026-04-28 15:00', $c[6], $svcKinesio,   $pDiego,  $locProvidencia, 5, 40000, null],
            ['2026-04-28 15:30', '2026-04-28 16:30', $c[0], $svcRelajante, $pAna,    $locLasCondes,   1, 35000, null],
            ['2026-04-28 17:00', '2026-04-28 18:00', $c[7], $svcKinesio,   $pAna,    $locProvidencia, 6, 40000, null],

            // Miércoles 29 abr
            ['2026-04-29 09:00', '2026-04-29 10:30', $c[7], $svcDrenaje,   $pMaria,  $locCentro,      3, 45000, 'paid'],
            ['2026-04-29 11:00', '2026-04-29 11:45', $c[8], $svcDeportivo, $pCarlos, $locCentro,      4, 28000, 'unpaid'],
            ['2026-04-29 13:00', '2026-04-29 14:00', $c[1], $svcKinesio,   $pCarlos, $locLasCondes,   7, 40000, null],
            ['2026-04-29 15:00', '2026-04-29 16:00', $c[9], $svcRelajante, $pAna,    $locProvidencia, 2, 35000, 'partial'],
            ['2026-04-29 16:30', '2026-04-29 18:00', $c[3], $svcDrenaje,   $pDiego,  $locProvidencia, 1, 45000, null],

            // Jueves 30 abr
            ['2026-04-30 09:30', '2026-04-30 10:30', $c[2], $svcRelajante, $pMaria,  $locCentro,      2, 35000, 'paid'],
            ['2026-04-30 11:00', '2026-04-30 12:00', $c[3], $svcKinesio,   $pCarlos, $locLasCondes,   3, 40000, 'paid'],
            ['2026-04-30 14:00', '2026-04-30 15:30', $c[4], $svcDrenaje,   $pDiego,  $locProvidencia, 6, 45000, null],
            ['2026-04-30 16:00', '2026-04-30 16:45', $c[5], $svcDeportivo, $pAna,    $locLasCondes,   1, 28000, null],
            ['2026-04-30 17:00', '2026-04-30 18:00', $c[8], $svcRelajante, $pMaria,  $locLasCondes,   5, 35000, null],

            // Viernes 1 may
            ['2026-05-01 09:00', '2026-05-01 10:00', $c[6], $svcRelajante, $pMaria,  $locCentro,      2, 35000, 'partial'],
            ['2026-05-01 10:30', '2026-05-01 11:15', $c[7], $svcDeportivo, $pCarlos, $locLasCondes,   1, 28000, null],
            ['2026-05-01 13:00', '2026-05-01 14:30', $c[8], $svcDrenaje,   $pAna,    $locProvidencia, 7, 45000, null],
            ['2026-05-01 15:00', '2026-05-01 16:00', $c[9], $svcKinesio,   $pDiego,  $locCentro,      2, 40000, 'paid'],
            ['2026-05-01 16:30', '2026-05-01 17:30', $c[0], $svcRelajante, $pAna,    $locLasCondes,   3, 35000, 'paid'],

            // Sábado 2 may
            ['2026-05-02 10:00', '2026-05-02 11:00', $c[0], $svcRelajante, $pMaria,  $locCentro,      3, 35000, 'paid'],
            ['2026-05-02 11:30', '2026-05-02 12:30', $c[1], $svcKinesio,   $pCarlos, $locLasCondes,   5, 40000, null],
            ['2026-05-02 14:00', '2026-05-02 15:30', $c[2], $svcDrenaje,   $pAna,    $locProvidencia, 1, 45000, null],
            ['2026-05-02 16:00', '2026-05-02 16:45', $c[5], $svcDeportivo, $pDiego,  $locCentro,      4, 28000, 'unpaid'],

            // Domingo 3 may
            ['2026-05-03 10:00', '2026-05-03 11:00', $c[3], $svcRelajante, $pDiego,  $locCentro,      2, 35000, 'partial'],
            ['2026-05-03 12:00', '2026-05-03 12:45', $c[4], $svcDeportivo, $pMaria,  $locLasCondes,   6, 28000, null],
            ['2026-05-03 14:00', '2026-05-03 15:00', $c[6], $svcKinesio,   $pCarlos, $locProvidencia, 1, 40000, null],
        ];

        foreach ($bookings as [$start, $end, $clientId, $serviceId, $providerId, $locationId, $statusId, $price, $payment]) {
            $bookingId = DB::table('bookings')->insertGetId([
                'client_id'   => $clientId,
                'service_id'  => $serviceId,
                'provider_id' => $providerId,
                'location_id' => $locationId,
                'status_id'   => $statusId,
                'start_time'  => $start,
                'end_time'    => $end,
                'price'       => $price,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);

            if ($payment !== null) {
                $paidAmount = match ($payment) {
                    'paid'    => $price,
                    'partial' => round($price * 0.5),
                    'unpaid'  => 0,
                };
                DB::table('sales')->insert([
                    'booking_id'     => $bookingId,
                    'total'          => $price,
                    'paid_amount'    => $paidAmount,
                    'payment_method' => $payment === 'unpaid' ? null : 'transferencia',
                    'paid_at'        => $payment === 'paid' ? now()->subDay() : null,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);
            }
        }
    }
}
