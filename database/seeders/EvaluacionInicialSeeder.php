<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EvaluacionInicialSeeder extends Seeder
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

        $locCentro = DB::table('locations')->where('name', 'Kinesilk Centro')->value('id');
        $pMaria = DB::table('providers')->where('email', 'maria@kinesilk.cl')->value('id');
        $client = DB::table('clients')->where('email', 'laura@mail.com')->value('id');
        $statusId = DB::table('booking_statuses')->where('name', 'Reservado')->value('id') ?? 1;

        // Servicio gratuito de evaluación inicial (idempotente por nombre)
        $serviceId = $upsert('services', ['name' => 'Consulta de Evaluación Inicial'], [
            'duration_minutes' => 30,
            'slot_interval_minutes' => 15,
            'min_duration_minutes' => 30,
            'max_duration_minutes' => 60,
            'price' => 0,
            'active' => true,
        ]);

        // Asignar el servicio a los providers de Centro (pivot con PK compuesta)
        $centroProviders = DB::table('providers')->where('location_id', $locCentro)->pluck('id');
        foreach ($centroProviders as $providerId) {
            DB::table('provider_service')->insertOrIgnore([
                'provider_id' => $providerId,
                'service_id' => $serviceId,
            ]);
        }

        // Reserva de evaluación para Laura — próximo lunes a las 08:30
        $bookingId = $upsert('bookings', [
            'provider_id' => $pMaria,
            'location_id' => $locCentro,
            'start_time' => '2026-06-01 08:30:00',
        ], [
            'client_id' => $client,
            'service_id' => $serviceId,
            'status_id' => $statusId,
            'end_time' => '2026-06-01 09:00:00',
            'price' => 0,
            'notes' => 'Evaluación inicial gratuita — primera visita.',
        ]);

        // Venta con total $0 — status: unpaid (sin transacciones registradas).
        // Cuando se agregue un servicio adicional, el admin actualiza sale.total
        // y el status pasa a unpaid → partial → paid según los pagos que ingresen.
        $upsert('sales', ['booking_id' => $bookingId], [
            'client_id' => $client,
            'total' => 0,
            'paid_amount' => 0,
            'payment_method' => null,
            'paid_at' => null,
        ]);
    }
}
