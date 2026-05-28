<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BookingNotesSeeder extends Seeder
{
    public function run(): void
    {
        // Clinical notes for pack sessions
        $packNotes = [
            'Vello crece más lento desde la última sesión. Buen resultado.',
            'Se reduce densidad notablemente. Próxima sesión aplicar menor fluencia.',
            'Zona axilar libre de vello en 80%. Mantener protocolo actual.',
            'Pequeñas foliculitis post-sesión. Ajustar cabezal y período de enfriamiento.',
            'Pack activo: paciente refiere mejoría entre sesiones. Sin reacciones adversas.',
            'Cliente refiere ardor moderado post-sesión. Reducir intensidad en próxima cita.',
            'Última sesión del ciclo. Se programa mantenimiento en 3 meses.',
            'Segunda sesión del pack completada. Excelente respuesta folicular.',
        ];

        // Clinical notes for past (non-pack) bookings
        $pastNotes = [
            'Contractura cervical reducida. Se incorpora calor previo como protocolo.',
            'Drenaje post-quirúrgico. Reducción de edema visible al tacto.',
            'Mejora en rango articular de rodilla. Incorporar ejercicios excéntricos domiciliarios.',
            'Masaje deportivo completado. Tensión muscular en isquiotibiales disminuida.',
            'Buena tolerancia al tratamiento. Paciente sigue indicaciones de autocuidado.',
            'Primer sesión de kinesiología. Evaluación funcional: limitación moderada, buen pronóstico.',
            'Dolor referido reducido. Se ajusta técnica de presión en zona lumbar.',
            'Sin incidentes. Rango articular dentro de lo esperado para esta etapa del tratamiento.',
        ];

        // Qualifying bookings: past OR linked to a pack session
        $packBookingIds = DB::table('pack_sessions')
            ->whereNotNull('booking_id')
            ->pluck('booking_id')
            ->unique();

        $pastBookingIds = DB::table('bookings')
            ->whereDate('start_time', '<', now()->toDateString())
            ->pluck('id');

        $qualifying = $packBookingIds->merge($pastBookingIds)->unique()->values();

        // Apply to every other booking (half)
        $noteIdx = 0;
        foreach ($qualifying->chunk(2) as $pair) {
            $bookingId = $pair->first();
            $note = $packBookingIds->contains($bookingId)
                ? $packNotes[$noteIdx % count($packNotes)]
                : $pastNotes[$noteIdx % count($pastNotes)];

            DB::table('bookings')
                ->where('id', $bookingId)
                ->update(['notes' => $note, 'updated_at' => now()]);

            $noteIdx++;
        }
    }
}
