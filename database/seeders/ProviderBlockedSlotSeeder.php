<?php

namespace Database\Seeders;

use App\Models\BlockedSlot;
use App\Models\Provider;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Crea bloqueos de horario de la semana actual para el profesional 1 (María),
 * para que se vean reflejados en la vista previa de disponibilidad (semana /
 * mes / rango) con el estilo rayado del calendario.
 *
 * Idempotente — re-correr no duplica: borra los bloqueos del marcador y vuelve
 * a crearlos para la semana en curso.
 *
 * Uso:
 *   php artisan db:seed --class=ProviderBlockedSlotSeeder
 */
class ProviderBlockedSlotSeeder extends Seeder
{
    private const MARKER = '[seeder] Bloqueo semanal';

    public function run(): void
    {
        $tz = 'America/Santiago';

        $provider = Provider::with('location')->find(1);
        if (! $provider || ! $provider->location) {
            $this->command->error('No existe el provider 1 con sucursal. Seeder de bloqueos abortado.');

            return;
        }

        $locationId = $provider->location_id;

        $this->command->info('══════════════════════════════════════════════════════════');
        $this->command->info(' ProviderBlockedSlotSeeder');
        $this->command->info('══════════════════════════════════════════════════════════');
        $this->command->info("Provider: #{$provider->id} {$provider->first_name} {$provider->last_name} | location #{$locationId}");

        // ── Limpia los bloqueos previos del seeder ────────────────────────────
        $removed = BlockedSlot::where('provider_id', $provider->id)
            ->where('reason', self::MARKER)
            ->delete();
        $this->command->info("Bloqueos previos eliminados: {$removed}");

        // ── Semana actual (domingo → sábado, alineada a la vista previa) ──────
        $weekStart = Carbon::now($tz)->startOfWeek(Carbon::SUNDAY);
        $this->command->info('Semana actual: '.$weekStart->copy()->addDays(6)->toDateString().' (desde '.$weekStart->toDateString().')');

        $blocks = [
            // Miércoles 14:00–16:00
            $weekStart->copy()->addDays(3)->setTime(14, 0, 0),
            // Jueves 17:00–18:00
            $weekStart->copy()->addDays(4)->setTime(17, 0, 0),
        ];

        $created = 0;
        foreach ($blocks as $start) {
            $end = $start->copy()->addMinutes($start->format('G') === '17' ? 60 : 120);

            BlockedSlot::create([
                'location_id' => $locationId,
                'provider_id' => $provider->id,
                // Se guarda en la hora local de la sucursal (sin conversion a UTC),
                // igual que el block-time-dialog, para que la API devuelva la
                // misma hora local que se muestre en la vista previa.
                'start_time' => $start,
                'end_time' => $end,
                'reason' => self::MARKER,
            ]);
            $created++;

            $this->command->info("  ✓ {$start->toDateTimeString()} → {$end->toDateTimeString()}");
        }

        $this->command->info(" Creados: {$created} bloqueo(s) de la semana actual.");
        $this->command->info('══════════════════════════════════════════════════════════');
    }
}
