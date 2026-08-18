<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class TestDataSeeder extends Seeder
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

        // ── Locations ─────────────────────────────────────────────────
        $locCentro = $upsert('locations', ['name' => 'Kinesilk Centro', 'address' => 'Av. Providencia 1234'], ['city' => 'Santiago', 'timezone' => 'America/Santiago', 'active' => true]);
        $locLasCondes = $upsert('locations', ['name' => 'Kinesilk Las Condes', 'address' => 'Av. Apoquindo 4500'], ['city' => 'Santiago', 'timezone' => 'America/Santiago', 'active' => true]);
        $locProvidencia = $upsert('locations', ['name' => 'Kinesilk Providencia', 'address' => 'Av. Pedro de Valdivia 210'], ['city' => 'Santiago', 'timezone' => 'America/Santiago', 'active' => true]);

        // ── Services ──────────────────────────────────────────────────
        $svcRelajante = $upsert('services', ['name' => 'Masaje Relajante'], ['duration_minutes' => 60, 'slot_interval_minutes' => 15, 'min_duration_minutes' => 30, 'max_duration_minutes' => 120, 'price' => 35000, 'active' => true]);
        $svcDeportivo = $upsert('services', ['name' => 'Masaje Deportivo'], ['duration_minutes' => 45, 'slot_interval_minutes' => 15, 'min_duration_minutes' => 30, 'max_duration_minutes' => 90, 'price' => 28000, 'active' => true]);
        $svcKinesio = $upsert('services', ['name' => 'Kinesiología'], ['duration_minutes' => 60, 'slot_interval_minutes' => 15, 'min_duration_minutes' => 45, 'max_duration_minutes' => 120, 'price' => 40000, 'active' => true]);
        $svcDrenaje = $upsert('services', ['name' => 'Drenaje Linfático'], ['duration_minutes' => 90, 'slot_interval_minutes' => 15, 'min_duration_minutes' => 60, 'max_duration_minutes' => 120, 'price' => 45000, 'active' => true]);

        // ── Providers ─────────────────────────────────────────────────
        // Centro: María, Carmen, Jorge
        $pMaria = $upsert('providers', ['email' => 'maria@kinesilk.cl'], ['first_name' => 'María', 'last_name' => 'González', 'phone' => '+56912345001', 'location_id' => $locCentro, 'active' => true]);
        $pCarmen = $upsert('providers', ['email' => 'carmen@kinesilk.cl'], ['first_name' => 'Carmen', 'last_name' => 'Lira', 'phone' => '+56912345005', 'location_id' => $locCentro, 'active' => true]);
        $pJorge = $upsert('providers', ['email' => 'jorge@kinesilk.cl'], ['first_name' => 'Jorge', 'last_name' => 'Peralta', 'phone' => '+56912345006', 'location_id' => $locCentro, 'active' => true]);
        // Las Condes: Carlos, Pilar, Sebastián
        $pCarlos = $upsert('providers', ['email' => 'carlos@kinesilk.cl'], ['first_name' => 'Carlos', 'last_name' => 'Rojas', 'phone' => '+56912345002', 'location_id' => $locLasCondes, 'active' => true]);
        $pPilar = $upsert('providers', ['email' => 'pilar@kinesilk.cl'], ['first_name' => 'Pilar', 'last_name' => 'Navarrete', 'phone' => '+56912345007', 'location_id' => $locLasCondes, 'active' => true]);
        $pSebas = $upsert('providers', ['email' => 'sebastian@kinesilk.cl'], ['first_name' => 'Sebastián', 'last_name' => 'Aguilar', 'phone' => '+56912345008', 'location_id' => $locLasCondes, 'active' => true]);
        // Providencia: Ana, Diego, Claudia
        $pAna = $upsert('providers', ['email' => 'ana@kinesilk.cl'], ['first_name' => 'Ana', 'last_name' => 'Fernández', 'phone' => '+56912345003', 'location_id' => $locProvidencia, 'active' => true]);
        $pDiego = $upsert('providers', ['email' => 'diego@kinesilk.cl'], ['first_name' => 'Diego', 'last_name' => 'Morales', 'phone' => '+56912345004', 'location_id' => $locProvidencia, 'active' => true]);
        $pClaudia = $upsert('providers', ['email' => 'claudia@kinesilk.cl'], ['first_name' => 'Claudia', 'last_name' => 'Sandoval', 'phone' => '+56912345009', 'location_id' => $locProvidencia, 'active' => true]);

        // ── Provider ↔ Service ────────────────────────────────────────
        // Pivot con PK compuesta: insertOrIgnore ignora los ya existentes.
        DB::table('provider_service')->insertOrIgnore([
            // Centro
            ['provider_id' => $pMaria, 'service_id' => $svcRelajante],
            ['provider_id' => $pMaria, 'service_id' => $svcDrenaje],
            ['provider_id' => $pCarmen, 'service_id' => $svcDeportivo],
            ['provider_id' => $pCarmen, 'service_id' => $svcKinesio],
            ['provider_id' => $pJorge, 'service_id' => $svcRelajante],
            ['provider_id' => $pJorge, 'service_id' => $svcKinesio],
            ['provider_id' => $pJorge, 'service_id' => $svcDrenaje],
            // Las Condes
            ['provider_id' => $pCarlos, 'service_id' => $svcDeportivo],
            ['provider_id' => $pCarlos, 'service_id' => $svcKinesio],
            ['provider_id' => $pPilar, 'service_id' => $svcRelajante],
            ['provider_id' => $pPilar, 'service_id' => $svcDrenaje],
            ['provider_id' => $pSebas, 'service_id' => $svcKinesio],
            ['provider_id' => $pSebas, 'service_id' => $svcDeportivo],
            // Providencia
            ['provider_id' => $pAna, 'service_id' => $svcRelajante],
            ['provider_id' => $pAna, 'service_id' => $svcKinesio],
            ['provider_id' => $pAna, 'service_id' => $svcDrenaje],
            ['provider_id' => $pDiego, 'service_id' => $svcKinesio],
            ['provider_id' => $pDiego, 'service_id' => $svcDeportivo],
            ['provider_id' => $pDiego, 'service_id' => $svcRelajante],
            ['provider_id' => $pClaudia, 'service_id' => $svcDrenaje],
            ['provider_id' => $pClaudia, 'service_id' => $svcRelajante],
        ]);

        // ── Booking statuses — IDs fijos para coincidir con el front ──
        $upsert('booking_statuses', ['id' => 1], ['name' => 'Reservado', 'color' => '#93c5fd', 'is_cancellation' => false]);
        $upsert('booking_statuses', ['id' => 2], ['name' => 'Confirmado', 'color' => '#fb923c', 'is_cancellation' => false]);
        $upsert('booking_statuses', ['id' => 3], ['name' => 'Asiste', 'color' => '#ec4899', 'is_cancellation' => false]);
        $upsert('booking_statuses', ['id' => 4], ['name' => 'No asistio', 'color' => '#f9a8d4', 'is_cancellation' => false]);
        $upsert('booking_statuses', ['id' => 5], ['name' => 'Pendiente', 'color' => '#fca5a5', 'is_cancellation' => false]);
        $upsert('booking_statuses', ['id' => 6], ['name' => 'En espera', 'color' => '#86efac', 'is_cancellation' => false]);
        $upsert('booking_statuses', ['id' => 7], ['name' => 'Cancelada', 'color' => '#d1d5db', 'is_cancellation' => true]);

        // ── Clients ───────────────────────────────────────────────────
        $c = [];
        $clients = [
            ['Laura', 'Martínez', 'laura@mail.com', '+56911111001', 'female', '12345678-5', 'Cliente regular, prefiere horarios de mañana'],
            ['Pedro', 'Soto', 'pedro@mail.com', '+56911111002', 'male', '87654321-0', 'Requiere disponibilidad post-work'],
            ['Valentina', 'López', 'vale@mail.com', '+56911111003', 'female', '11222333-4', null],
            ['Andrés', 'García', 'andres@mail.com', '+56911111004', 'male', '44556677-8', 'Alergia a aceites esenciales'],
            ['Camila', 'Torres', 'camila@mail.com', '+56911111005', 'female', '99887766-1', null],
            ['Rodrigo', 'Vega', 'rodrigo@mail.com', '+56911111006', 'male', '55667788-3', 'Prefiere masajes deportivo'],
            ['Sofía', 'Herrera', 'sofia@mail.com', '+56911111007', 'female', '22334455-9', null],
            ['Matías', 'Díaz', 'matias@mail.com', '+56911111008', 'male', '77112233-K', 'Programación de sesiones semanales'],
            ['Isadora', 'Muñoz', 'isadora@mail.com', '+56911111009', 'female', '33445566-7', null],
            ['Felipe', 'Castro', 'felipe@mail.com', '+56911111010', 'male', '66778899-2', 'Tiene pack de kinesiología activo'],
        ];
        foreach ($clients as [$fn, $ln, $email, $phone, $gender, $rut, $notes]) {
            $c[] = $upsert('clients', ['email' => $email], [
                'first_name' => $fn, 'last_name' => $ln,
                'phone' => $phone, 'rut' => $rut, 'gender' => $gender,
                'notes' => $notes, 'active' => true,
            ]);
        }

        // ── Users ─────────────────────────────────────────────────────
        $upsert('users', ['email' => 'admin@kinesilk.cl'], ['name' => 'Admin Kinesilk', 'password' => Hash::make('password'), 'role' => 'admin', 'provider_id' => null]);
        $upsert('users', ['email' => 'wc@kinesilk.cl'], ['name' => 'WooCommerce Bridge', 'password' => Hash::make('wc-bridge-2026'), 'role' => 'woocommerce', 'provider_id' => null]);
        $upsert('users', ['email' => 'agent@kinesilk.cl'], ['name' => 'Conversational Agent', 'password' => Hash::make('agent-2026'), 'role' => 'agent', 'provider_id' => null]);
        // Centro
        $upsert('users', ['email' => 'maria@kinesilk.cl'], ['name' => 'María González', 'password' => Hash::make('password'), 'role' => 'provider', 'provider_id' => $pMaria]);
        $upsert('users', ['email' => 'carmen@kinesilk.cl'], ['name' => 'Carmen Lira', 'password' => Hash::make('password'), 'role' => 'provider', 'provider_id' => $pCarmen]);
        $upsert('users', ['email' => 'jorge@kinesilk.cl'], ['name' => 'Jorge Peralta', 'password' => Hash::make('password'), 'role' => 'provider', 'provider_id' => $pJorge]);
        // Las Condes
        $upsert('users', ['email' => 'carlos@kinesilk.cl'], ['name' => 'Carlos Rojas', 'password' => Hash::make('password'), 'role' => 'provider', 'provider_id' => $pCarlos]);
        $upsert('users', ['email' => 'pilar@kinesilk.cl'], ['name' => 'Pilar Navarrete', 'password' => Hash::make('password'), 'role' => 'provider', 'provider_id' => $pPilar]);
        $upsert('users', ['email' => 'sebastian@kinesilk.cl'], ['name' => 'Sebastián Aguilar', 'password' => Hash::make('password'), 'role' => 'provider', 'provider_id' => $pSebas]);
        // Providencia
        $upsert('users', ['email' => 'ana@kinesilk.cl'], ['name' => 'Ana Fernández', 'password' => Hash::make('password'), 'role' => 'provider', 'provider_id' => $pAna]);
        $upsert('users', ['email' => 'diego@kinesilk.cl'], ['name' => 'Diego Morales', 'password' => Hash::make('password'), 'role' => 'provider', 'provider_id' => $pDiego]);
        $upsert('users', ['email' => 'claudia@kinesilk.cl'], ['name' => 'Claudia Sandoval', 'password' => Hash::make('password'), 'role' => 'provider', 'provider_id' => $pClaudia]);

        // ── Bookings semana 4–10 may 2026 ────────────────────────────
        // [start, end, client, service, provider, location, status, price, payment]
        // status: 1=Reservado 2=Confirmado 3=Asiste 4=NoAsistio 5=Pendiente 6=EnEspera 7=Cancelada
        // payment: null=sin sale | 'paid'=total | 'partial'=50% | 'unpaid'=sale sin pago

        $bookings = [
            // Lunes 4 may
            ['2026-05-04 09:00', '2026-05-04 10:00', $c[0], $svcRelajante, $pMaria, $locCentro, 2, 35000, 'paid'],
            ['2026-05-04 10:15', '2026-05-04 11:15', $c[1], $svcRelajante, $pMaria, $locCentro, 3, 35000, 'paid'],
            ['2026-05-04 11:30', '2026-05-04 12:15', $c[2], $svcDeportivo, $pCarlos, $locLasCondes, 2, 28000, 'partial'],
            ['2026-05-04 14:00', '2026-05-04 15:00', $c[3], $svcKinesio, $pAna, $locProvidencia, 1, 40000, null],
            ['2026-05-04 15:30', '2026-05-04 17:00', $c[4], $svcDrenaje, $pDiego, $locCentro, 5, 45000, null],
            ['2026-05-04 17:15', '2026-05-04 18:15', $c[5], $svcRelajante, $pAna, $locLasCondes, 6, 35000, null],

            // Martes 5 may
            ['2026-05-05 09:00', '2026-05-05 10:30', $c[6], $svcDrenaje, $pMaria, $locCentro, 2, 45000, 'paid'],
            ['2026-05-05 10:30', '2026-05-05 11:15', $c[7], $svcDeportivo, $pCarlos, $locLasCondes, 3, 28000, 'paid'],
            ['2026-05-05 11:30', '2026-05-05 12:30', $c[8], $svcKinesio, $pCarlos, $locLasCondes, 2, 40000, 'partial'],
            ['2026-05-05 14:00', '2026-05-05 15:00', $c[9], $svcKinesio, $pDiego, $locProvidencia, 1, 40000, null],
            ['2026-05-05 15:30', '2026-05-05 16:30', $c[0], $svcRelajante, $pAna, $locLasCondes, 5, 35000, null],
            ['2026-05-05 17:00', '2026-05-05 18:30', $c[3], $svcDrenaje, $pDiego, $locProvidencia, 6, 45000, null],

            // Miércoles 6 may
            ['2026-05-06 09:00', '2026-05-06 10:00', $c[1], $svcRelajante, $pMaria, $locCentro, 3, 35000, 'paid'],
            ['2026-05-06 10:15', '2026-05-06 11:00', $c[2], $svcDeportivo, $pCarlos, $locCentro, 4, 28000, 'unpaid'],
            ['2026-05-06 11:30', '2026-05-06 12:30', $c[4], $svcKinesio, $pAna, $locProvidencia, 2, 40000, 'partial'],
            ['2026-05-06 13:00', '2026-05-06 14:00', $c[5], $svcRelajante, $pAna, $locLasCondes, 7, 35000, null],
            ['2026-05-06 14:30', '2026-05-06 16:00', $c[6], $svcDrenaje, $pMaria, $locCentro, 1, 45000, null],
            ['2026-05-06 16:30', '2026-05-06 17:30', $c[9], $svcKinesio, $pDiego, $locProvidencia, 2, 40000, 'paid'],

            // Jueves 7 may
            ['2026-05-07 09:00', '2026-05-07 10:00', $c[7], $svcRelajante, $pMaria, $locCentro, 2, 35000, 'paid'],
            ['2026-05-07 10:15', '2026-05-07 11:00', $c[8], $svcDeportivo, $pCarlos, $locLasCondes, 3, 28000, 'paid'],
            ['2026-05-07 11:30', '2026-05-07 13:00', $c[0], $svcDrenaje, $pMaria, $locCentro, 2, 45000, 'partial'],
            ['2026-05-07 14:00', '2026-05-07 15:00', $c[1], $svcKinesio, $pAna, $locProvidencia, 5, 40000, null],
            ['2026-05-07 15:30', '2026-05-07 16:15', $c[4], $svcDeportivo, $pDiego, $locCentro, 1, 28000, null],
            ['2026-05-07 17:00', '2026-05-07 18:00', $c[3], $svcRelajante, $pAna, $locLasCondes, 6, 35000, null],

            // Viernes 8 may
            ['2026-05-08 09:00', '2026-05-08 10:00', $c[2], $svcRelajante, $pMaria, $locCentro, 2, 35000, 'paid'],
            ['2026-05-08 10:30', '2026-05-08 12:00', $c[5], $svcDrenaje, $pAna, $locProvidencia, 3, 45000, 'paid'],
            ['2026-05-08 12:15', '2026-05-08 13:00', $c[6], $svcDeportivo, $pCarlos, $locLasCondes, 7, 28000, null],
            ['2026-05-08 14:00', '2026-05-08 15:00', $c[7], $svcKinesio, $pDiego, $locCentro, 2, 40000, 'partial'],
            ['2026-05-08 15:30', '2026-05-08 16:30', $c[9], $svcRelajante, $pMaria, $locLasCondes, 1, 35000, null],
            ['2026-05-08 17:00', '2026-05-08 18:00', $c[8], $svcKinesio, $pCarlos, $locLasCondes, 4, 40000, 'unpaid'],

            // Sábado 9 may
            ['2026-05-09 10:00', '2026-05-09 11:00', $c[0], $svcRelajante, $pMaria, $locCentro, 3, 35000, 'paid'],
            ['2026-05-09 11:30', '2026-05-09 12:15', $c[3], $svcDeportivo, $pCarlos, $locLasCondes, 2, 28000, 'partial'],
            ['2026-05-09 12:30', '2026-05-09 14:00', $c[4], $svcDrenaje, $pAna, $locProvidencia, 1, 45000, null],
            ['2026-05-09 15:00', '2026-05-09 16:00', $c[1], $svcKinesio, $pDiego, $locCentro, 5, 40000, null],

            // Domingo 10 may
            ['2026-05-10 10:00', '2026-05-10 11:00', $c[5], $svcRelajante, $pDiego, $locCentro, 2, 35000, 'partial'],
            ['2026-05-10 11:30', '2026-05-10 13:00', $c[7], $svcDrenaje, $pMaria, $locCentro, 1, 45000, null],
            ['2026-05-10 14:00', '2026-05-10 15:00', $c[9], $svcKinesio, $pCarlos, $locProvidencia, 6, 40000, null],
        ];

        // ── Service packs ─────────────────────────────────────────────
        $packRelajante6 = $upsert('service_packs', ['name' => 'Pack Masaje Relajante x6'], ['service_id' => $svcRelajante, 'total_sessions' => 6, 'price' => 189000, 'active' => true]);
        $packKinesio8 = $upsert('service_packs', ['name' => 'Pack Kinesiología x8'], ['service_id' => $svcKinesio, 'total_sessions' => 8, 'price' => 288000, 'active' => true]);
        $packDrenaje4 = $upsert('service_packs', ['name' => 'Pack Drenaje Linfático x4'], ['service_id' => $svcDrenaje, 'total_sessions' => 4, 'price' => 160000, 'active' => true]);

        // ── Client packs ──────────────────────────────────────────────
        // c[0]=Laura  — relajante x6, usadas 2 (lun idx0, mar idx10, sáb idx30 = sesiones 1/2/3)
        $cp1 = $upsert('client_packs', ['client_id' => $c[0], 'service_pack_id' => $packRelajante6], ['total_sessions' => 6, 'used_sessions' => 2, 'status' => 'active']);
        // c[9]=Felipe — kinesiología x8, usadas 2 (mar idx9, mié idx17, dom idx36 = sesiones 1/2/3)
        $cp2 = $upsert('client_packs', ['client_id' => $c[9], 'service_pack_id' => $packKinesio8], ['total_sessions' => 8, 'used_sessions' => 2, 'status' => 'active']);
        // c[6]=Sofía  — drenaje x4, usadas 2 (mar idx6, mié idx16 = sesiones 1/2)
        $cp3 = $upsert('client_packs', ['client_id' => $c[6], 'service_pack_id' => $packDrenaje4], ['total_sessions' => 4, 'used_sessions' => 2, 'status' => 'active']);

        $bookingIds = [];
        foreach ($bookings as [$start, $end, $clientId, $serviceId, $providerId, $locationId, $statusId, $price, $payment]) {
            $bookingId = $upsert('bookings', [
                'client_id' => $clientId,
                'provider_id' => $providerId,
                'location_id' => $locationId,
                'start_time' => $start,
            ], [
                'service_id' => $serviceId,
                'status_id' => $statusId,
                'end_time' => $end,
                'price' => $price,
                'created_via' => 'admin_calendar',
                'last_modified_via' => 'admin_calendar',
            ]);
            $bookingIds[] = $bookingId;

            if ($payment !== null) {
                $paidAmount = match ($payment) {
                    'paid' => $price,
                    'partial' => round($price * 0.5),
                    'unpaid' => 0,
                };
                $upsert('sales', ['booking_id' => $bookingId], [
                    'total' => $price,
                    'paid_amount' => $paidAmount,
                    'payment_method' => $payment === 'unpaid' ? null : 'transferencia',
                    'paid_at' => $payment === 'paid' ? now()->subDay() : null,
                ]);
            }
        }

        // ── Pack sessions ─────────────────────────────────────────────
        // Índices del array $bookings (0-based):
        // 0=lun c[0] relajante | 10=mar c[0] relajante | 30=sáb c[0] relajante
        // 9=mar c[9] kinesio   | 17=mié c[9] kinesio   | 36=dom c[9] kinesio
        // 6=mar c[6] drenaje   | 16=mié c[6] drenaje

        // Laura (c[0]) — relajante x6: sesión 1(lun), 2(mar), 3(sáb) agendada, 4-6 pendientes
        $upsert('pack_sessions', ['client_pack_id' => $cp1, 'session_number' => 1], ['booking_id' => $bookingIds[0], 'status' => 'attended', 'attended_at' => '2026-05-04 10:00:00']);
        $upsert('pack_sessions', ['client_pack_id' => $cp1, 'session_number' => 2], ['booking_id' => $bookingIds[10], 'status' => 'attended', 'attended_at' => '2026-05-05 16:30:00']);
        $upsert('pack_sessions', ['client_pack_id' => $cp1, 'session_number' => 3], ['booking_id' => $bookingIds[30], 'status' => 'scheduled', 'attended_at' => null]);
        $upsert('pack_sessions', ['client_pack_id' => $cp1, 'session_number' => 4], ['booking_id' => null, 'status' => 'pending', 'attended_at' => null]);
        $upsert('pack_sessions', ['client_pack_id' => $cp1, 'session_number' => 5], ['booking_id' => null, 'status' => 'pending', 'attended_at' => null]);
        $upsert('pack_sessions', ['client_pack_id' => $cp1, 'session_number' => 6], ['booking_id' => null, 'status' => 'pending', 'attended_at' => null]);

        // Felipe (c[9]) — kinesiología x8: sesión 1(mar), 2(mié), 3(dom) agendada, 4-8 pendientes
        $upsert('pack_sessions', ['client_pack_id' => $cp2, 'session_number' => 1], ['booking_id' => $bookingIds[9], 'status' => 'attended', 'attended_at' => '2026-05-05 15:00:00']);
        $upsert('pack_sessions', ['client_pack_id' => $cp2, 'session_number' => 2], ['booking_id' => $bookingIds[17], 'status' => 'attended', 'attended_at' => '2026-05-06 17:30:00']);
        $upsert('pack_sessions', ['client_pack_id' => $cp2, 'session_number' => 3], ['booking_id' => $bookingIds[36], 'status' => 'scheduled', 'attended_at' => null]);
        $upsert('pack_sessions', ['client_pack_id' => $cp2, 'session_number' => 4], ['booking_id' => null, 'status' => 'pending', 'attended_at' => null]);
        $upsert('pack_sessions', ['client_pack_id' => $cp2, 'session_number' => 5], ['booking_id' => null, 'status' => 'pending', 'attended_at' => null]);
        $upsert('pack_sessions', ['client_pack_id' => $cp2, 'session_number' => 6], ['booking_id' => null, 'status' => 'pending', 'attended_at' => null]);
        $upsert('pack_sessions', ['client_pack_id' => $cp2, 'session_number' => 7], ['booking_id' => null, 'status' => 'pending', 'attended_at' => null]);
        $upsert('pack_sessions', ['client_pack_id' => $cp2, 'session_number' => 8], ['booking_id' => null, 'status' => 'pending', 'attended_at' => null]);

        // Sofía (c[6]) — drenaje x4: sesión 1(mar), 2(mié), 3-4 pendientes
        $upsert('pack_sessions', ['client_pack_id' => $cp3, 'session_number' => 1], ['booking_id' => $bookingIds[6], 'status' => 'attended', 'attended_at' => '2026-05-05 10:30:00']);
        $upsert('pack_sessions', ['client_pack_id' => $cp3, 'session_number' => 2], ['booking_id' => $bookingIds[16], 'status' => 'attended', 'attended_at' => '2026-05-06 16:00:00']);
        $upsert('pack_sessions', ['client_pack_id' => $cp3, 'session_number' => 3], ['booking_id' => null, 'status' => 'pending', 'attended_at' => null]);
        $upsert('pack_sessions', ['client_pack_id' => $cp3, 'session_number' => 4], ['booking_id' => null, 'status' => 'pending', 'attended_at' => null]);
    }
}