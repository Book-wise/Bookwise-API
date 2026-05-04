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
        $locCentro      = DB::table('locations')->insertGetId(['name' => 'Kinesilk Centro',      'address' => 'Av. Providencia 1234',      'city' => 'Santiago', 'timezone' => 'America/Santiago', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $locLasCondes   = DB::table('locations')->insertGetId(['name' => 'Kinesilk Las Condes',  'address' => 'Av. Apoquindo 4500',        'city' => 'Santiago', 'timezone' => 'America/Santiago', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $locProvidencia = DB::table('locations')->insertGetId(['name' => 'Kinesilk Providencia', 'address' => 'Av. Pedro de Valdivia 210', 'city' => 'Santiago', 'timezone' => 'America/Santiago', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);

        // ── Services ──────────────────────────────────────────────────
        $svcRelajante = DB::table('services')->insertGetId(['name' => 'Masaje Relajante',  'duration_minutes' => 60, 'slot_interval_minutes' => 15, 'min_duration_minutes' => 30, 'max_duration_minutes' => 120, 'price' => 35000, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $svcDeportivo = DB::table('services')->insertGetId(['name' => 'Masaje Deportivo',  'duration_minutes' => 45, 'slot_interval_minutes' => 15, 'min_duration_minutes' => 30, 'max_duration_minutes' => 90,  'price' => 28000, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $svcKinesio   = DB::table('services')->insertGetId(['name' => 'Kinesiología',      'duration_minutes' => 60, 'slot_interval_minutes' => 15, 'min_duration_minutes' => 45, 'max_duration_minutes' => 120, 'price' => 40000, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $svcDrenaje   = DB::table('services')->insertGetId(['name' => 'Drenaje Linfático', 'duration_minutes' => 90, 'slot_interval_minutes' => 15, 'min_duration_minutes' => 60, 'max_duration_minutes' => 120, 'price' => 45000, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);

        // ── Providers ─────────────────────────────────────────────────
        $pMaria  = DB::table('providers')->insertGetId(['first_name' => 'María',  'last_name' => 'González', 'email' => 'maria@kinesilk.cl',  'phone' => '+56912345001', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $pCarlos = DB::table('providers')->insertGetId(['first_name' => 'Carlos', 'last_name' => 'Rojas',    'email' => 'carlos@kinesilk.cl', 'phone' => '+56912345002', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $pAna    = DB::table('providers')->insertGetId(['first_name' => 'Ana',    'last_name' => 'Fernández','email' => 'ana@kinesilk.cl',    'phone' => '+56912345003', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $pDiego  = DB::table('providers')->insertGetId(['first_name' => 'Diego',  'last_name' => 'Morales',  'email' => 'diego@kinesilk.cl',  'phone' => '+56912345004', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);

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
            ['Laura',     'Martínez', 'laura@mail.com',   '+56911111001', 'female'],
            ['Pedro',     'Soto',     'pedro@mail.com',   '+56911111002', 'male'],
            ['Valentina', 'López',    'vale@mail.com',    '+56911111003', 'female'],
            ['Andrés',    'García',   'andres@mail.com',  '+56911111004', 'male'],
            ['Camila',    'Torres',   'camila@mail.com',  '+56911111005', 'female'],
            ['Rodrigo',   'Vega',     'rodrigo@mail.com', '+56911111006', 'male'],
            ['Sofía',     'Herrera',  'sofia@mail.com',   '+56911111007', 'female'],
            ['Matías',    'Díaz',     'matias@mail.com',  '+56911111008', 'male'],
            ['Isadora',   'Muñoz',    'isadora@mail.com', '+56911111009', 'female'],
            ['Felipe',    'Castro',   'felipe@mail.com',  '+56911111010', 'male'],
        ] as [$fn, $ln, $email, $phone, $gender]) {
            $c[] = DB::table('clients')->insertGetId([
                'first_name' => $fn,    'last_name' => $ln,
                'email'      => $email, 'phone'     => $phone,
                'gender'     => $gender,'active'    => true,
                'created_at' => now(),  'updated_at' => now(),
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

        // ── Bookings semana 4–10 may 2026 ────────────────────────────
        // [start, end, client, service, provider, location, status, price, payment]
        // status: 1=Reservado 2=Confirmado 3=Asiste 4=NoAsistio 5=Pendiente 6=EnEspera 7=Cancelada
        // payment: null=sin sale | 'paid'=total | 'partial'=50% | 'unpaid'=sale sin pago

        $bookings = [
            // Lunes 4 may
            ['2026-05-04 09:00', '2026-05-04 10:00', $c[0], $svcRelajante, $pMaria,  $locCentro,      2, 35000, 'paid'],
            ['2026-05-04 10:15', '2026-05-04 11:15', $c[1], $svcRelajante, $pMaria,  $locCentro,      3, 35000, 'paid'],
            ['2026-05-04 11:30', '2026-05-04 12:15', $c[2], $svcDeportivo, $pCarlos, $locLasCondes,   2, 28000, 'partial'],
            ['2026-05-04 14:00', '2026-05-04 15:00', $c[3], $svcKinesio,   $pAna,    $locProvidencia, 1, 40000, null],
            ['2026-05-04 15:30', '2026-05-04 17:00', $c[4], $svcDrenaje,   $pDiego,  $locCentro,      5, 45000, null],
            ['2026-05-04 17:15', '2026-05-04 18:15', $c[5], $svcRelajante, $pAna,    $locLasCondes,   6, 35000, null],

            // Martes 5 may
            ['2026-05-05 09:00', '2026-05-05 10:30', $c[6], $svcDrenaje,   $pMaria,  $locCentro,      2, 45000, 'paid'],
            ['2026-05-05 10:30', '2026-05-05 11:15', $c[7], $svcDeportivo, $pCarlos, $locLasCondes,   3, 28000, 'paid'],
            ['2026-05-05 11:30', '2026-05-05 12:30', $c[8], $svcKinesio,   $pCarlos, $locLasCondes,   2, 40000, 'partial'],
            ['2026-05-05 14:00', '2026-05-05 15:00', $c[9], $svcKinesio,   $pDiego,  $locProvidencia, 1, 40000, null],
            ['2026-05-05 15:30', '2026-05-05 16:30', $c[0], $svcRelajante, $pAna,    $locLasCondes,   5, 35000, null],
            ['2026-05-05 17:00', '2026-05-05 18:30', $c[3], $svcDrenaje,   $pDiego,  $locProvidencia, 6, 45000, null],

            // Miércoles 6 may
            ['2026-05-06 09:00', '2026-05-06 10:00', $c[1], $svcRelajante, $pMaria,  $locCentro,      3, 35000, 'paid'],
            ['2026-05-06 10:15', '2026-05-06 11:00', $c[2], $svcDeportivo, $pCarlos, $locCentro,      4, 28000, 'unpaid'],
            ['2026-05-06 11:30', '2026-05-06 12:30', $c[4], $svcKinesio,   $pAna,    $locProvidencia, 2, 40000, 'partial'],
            ['2026-05-06 13:00', '2026-05-06 14:00', $c[5], $svcRelajante, $pAna,    $locLasCondes,   7, 35000, null],
            ['2026-05-06 14:30', '2026-05-06 16:00', $c[6], $svcDrenaje,   $pMaria,  $locCentro,      1, 45000, null],
            ['2026-05-06 16:30', '2026-05-06 17:30', $c[9], $svcKinesio,   $pDiego,  $locProvidencia, 2, 40000, 'paid'],

            // Jueves 7 may
            ['2026-05-07 09:00', '2026-05-07 10:00', $c[7], $svcRelajante, $pMaria,  $locCentro,      2, 35000, 'paid'],
            ['2026-05-07 10:15', '2026-05-07 11:00', $c[8], $svcDeportivo, $pCarlos, $locLasCondes,   3, 28000, 'paid'],
            ['2026-05-07 11:30', '2026-05-07 13:00', $c[0], $svcDrenaje,   $pMaria,  $locCentro,      2, 45000, 'partial'],
            ['2026-05-07 14:00', '2026-05-07 15:00', $c[1], $svcKinesio,   $pAna,    $locProvidencia, 5, 40000, null],
            ['2026-05-07 15:30', '2026-05-07 16:15', $c[4], $svcDeportivo, $pDiego,  $locCentro,      1, 28000, null],
            ['2026-05-07 17:00', '2026-05-07 18:00', $c[3], $svcRelajante, $pAna,    $locLasCondes,   6, 35000, null],

            // Viernes 8 may
            ['2026-05-08 09:00', '2026-05-08 10:00', $c[2], $svcRelajante, $pMaria,  $locCentro,      2, 35000, 'paid'],
            ['2026-05-08 10:30', '2026-05-08 12:00', $c[5], $svcDrenaje,   $pAna,    $locProvidencia, 3, 45000, 'paid'],
            ['2026-05-08 12:15', '2026-05-08 13:00', $c[6], $svcDeportivo, $pCarlos, $locLasCondes,   7, 28000, null],
            ['2026-05-08 14:00', '2026-05-08 15:00', $c[7], $svcKinesio,   $pDiego,  $locCentro,      2, 40000, 'partial'],
            ['2026-05-08 15:30', '2026-05-08 16:30', $c[9], $svcRelajante, $pMaria,  $locLasCondes,   1, 35000, null],
            ['2026-05-08 17:00', '2026-05-08 18:00', $c[8], $svcKinesio,   $pCarlos, $locLasCondes,   4, 40000, 'unpaid'],

            // Sábado 9 may
            ['2026-05-09 10:00', '2026-05-09 11:00', $c[0], $svcRelajante, $pMaria,  $locCentro,      3, 35000, 'paid'],
            ['2026-05-09 11:30', '2026-05-09 12:15', $c[3], $svcDeportivo, $pCarlos, $locLasCondes,   2, 28000, 'partial'],
            ['2026-05-09 12:30', '2026-05-09 14:00', $c[4], $svcDrenaje,   $pAna,    $locProvidencia, 1, 45000, null],
            ['2026-05-09 15:00', '2026-05-09 16:00', $c[1], $svcKinesio,   $pDiego,  $locCentro,      5, 40000, null],

            // Domingo 10 may
            ['2026-05-10 10:00', '2026-05-10 11:00', $c[5], $svcRelajante, $pDiego,  $locCentro,      2, 35000, 'partial'],
            ['2026-05-10 11:30', '2026-05-10 13:00', $c[7], $svcDrenaje,   $pMaria,  $locCentro,      1, 45000, null],
            ['2026-05-10 14:00', '2026-05-10 15:00', $c[9], $svcKinesio,   $pCarlos, $locProvidencia, 6, 40000, null],
        ];

        // ── Service packs ─────────────────────────────────────────────
        $packRelajante6 = DB::table('service_packs')->insertGetId(['service_id' => $svcRelajante, 'name' => 'Pack Masaje Relajante x6', 'total_sessions' => 6, 'price' => 189000, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $packKinesio8   = DB::table('service_packs')->insertGetId(['service_id' => $svcKinesio,   'name' => 'Pack Kinesiología x8',     'total_sessions' => 8, 'price' => 288000, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $packDrenaje4   = DB::table('service_packs')->insertGetId(['service_id' => $svcDrenaje,   'name' => 'Pack Drenaje Linfático x4','total_sessions' => 4, 'price' => 160000, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);

        // ── Client packs ──────────────────────────────────────────────
        // c[0]=Laura  — relajante x6, usadas 2 (lun idx0, mar idx10, sáb idx30 = sesiones 1/2/3)
        $cp1 = DB::table('client_packs')->insertGetId(['client_id' => $c[0], 'service_pack_id' => $packRelajante6, 'total_sessions' => 6, 'used_sessions' => 2, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        // c[9]=Felipe — kinesiología x8, usadas 2 (mar idx9, mié idx17, dom idx36 = sesiones 1/2/3)
        $cp2 = DB::table('client_packs')->insertGetId(['client_id' => $c[9], 'service_pack_id' => $packKinesio8,   'total_sessions' => 8, 'used_sessions' => 2, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        // c[6]=Sofía  — drenaje x4, usadas 2 (mar idx6, mié idx16 = sesiones 1/2)
        $cp3 = DB::table('client_packs')->insertGetId(['client_id' => $c[6], 'service_pack_id' => $packDrenaje4,   'total_sessions' => 4, 'used_sessions' => 2, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        $bookingIds = [];
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
            $bookingIds[] = $bookingId;

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

        // ── Pack sessions ─────────────────────────────────────────────
        // Índices del array $bookings (0-based):
        // 0=lun c[0] relajante | 10=mar c[0] relajante | 30=sáb c[0] relajante
        // 9=mar c[9] kinesio   | 17=mié c[9] kinesio   | 36=dom c[9] kinesio
        // 6=mar c[6] drenaje   | 16=mié c[6] drenaje

        // Laura (c[0]) — relajante x6: sesión 1(lun), 2(mar), 3(sáb) agendada, 4-6 pendientes
        DB::table('pack_sessions')->insert([
            ['client_pack_id' => $cp1, 'booking_id' => $bookingIds[0],  'session_number' => 1, 'status' => 'attended',  'attended_at' => '2026-05-04 10:00:00', 'created_at' => now(), 'updated_at' => now()],
            ['client_pack_id' => $cp1, 'booking_id' => $bookingIds[10], 'session_number' => 2, 'status' => 'attended',  'attended_at' => '2026-05-05 16:30:00', 'created_at' => now(), 'updated_at' => now()],
            ['client_pack_id' => $cp1, 'booking_id' => $bookingIds[30], 'session_number' => 3, 'status' => 'scheduled', 'attended_at' => null,                  'created_at' => now(), 'updated_at' => now()],
            ['client_pack_id' => $cp1, 'booking_id' => null,            'session_number' => 4, 'status' => 'pending',   'attended_at' => null,                  'created_at' => now(), 'updated_at' => now()],
            ['client_pack_id' => $cp1, 'booking_id' => null,            'session_number' => 5, 'status' => 'pending',   'attended_at' => null,                  'created_at' => now(), 'updated_at' => now()],
            ['client_pack_id' => $cp1, 'booking_id' => null,            'session_number' => 6, 'status' => 'pending',   'attended_at' => null,                  'created_at' => now(), 'updated_at' => now()],
        ]);

        // Felipe (c[9]) — kinesiología x8: sesión 1(mar), 2(mié), 3(dom) agendada, 4-8 pendientes
        DB::table('pack_sessions')->insert([
            ['client_pack_id' => $cp2, 'booking_id' => $bookingIds[9],  'session_number' => 1, 'status' => 'attended',  'attended_at' => '2026-05-05 15:00:00', 'created_at' => now(), 'updated_at' => now()],
            ['client_pack_id' => $cp2, 'booking_id' => $bookingIds[17], 'session_number' => 2, 'status' => 'attended',  'attended_at' => '2026-05-06 17:30:00', 'created_at' => now(), 'updated_at' => now()],
            ['client_pack_id' => $cp2, 'booking_id' => $bookingIds[36], 'session_number' => 3, 'status' => 'scheduled', 'attended_at' => null,                  'created_at' => now(), 'updated_at' => now()],
            ['client_pack_id' => $cp2, 'booking_id' => null,            'session_number' => 4, 'status' => 'pending',   'attended_at' => null,                  'created_at' => now(), 'updated_at' => now()],
            ['client_pack_id' => $cp2, 'booking_id' => null,            'session_number' => 5, 'status' => 'pending',   'attended_at' => null,                  'created_at' => now(), 'updated_at' => now()],
            ['client_pack_id' => $cp2, 'booking_id' => null,            'session_number' => 6, 'status' => 'pending',   'attended_at' => null,                  'created_at' => now(), 'updated_at' => now()],
            ['client_pack_id' => $cp2, 'booking_id' => null,            'session_number' => 7, 'status' => 'pending',   'attended_at' => null,                  'created_at' => now(), 'updated_at' => now()],
            ['client_pack_id' => $cp2, 'booking_id' => null,            'session_number' => 8, 'status' => 'pending',   'attended_at' => null,                  'created_at' => now(), 'updated_at' => now()],
        ]);

        // Sofía (c[6]) — drenaje x4: sesión 1(mar), 2(mié), 3-4 pendientes
        DB::table('pack_sessions')->insert([
            ['client_pack_id' => $cp3, 'booking_id' => $bookingIds[6],  'session_number' => 1, 'status' => 'attended',  'attended_at' => '2026-05-05 10:30:00', 'created_at' => now(), 'updated_at' => now()],
            ['client_pack_id' => $cp3, 'booking_id' => $bookingIds[16], 'session_number' => 2, 'status' => 'attended',  'attended_at' => '2026-05-06 16:00:00', 'created_at' => now(), 'updated_at' => now()],
            ['client_pack_id' => $cp3, 'booking_id' => null,            'session_number' => 3, 'status' => 'pending',   'attended_at' => null,                  'created_at' => now(), 'updated_at' => now()],
            ['client_pack_id' => $cp3, 'booking_id' => null,            'session_number' => 4, 'status' => 'pending',   'attended_at' => null,                  'created_at' => now(), 'updated_at' => now()],
        ]);
    }
    }
}
