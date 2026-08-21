<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class PuntaArenasSeeder extends Seeder
{
    public function run(): void
    {
        // Helper idempotente (mismo patrón que TestDataSeeder). Claves naturales:
        // location (name+address), provider/user (email).
        $upsert = function (string $table, array $key, array $values): int {
            if (DB::table($table)->where($key)->exists()) {
                DB::table($table)->where($key)->update(array_merge($values, ['updated_at' => now()]));
            } else {
                DB::table($table)->insert(array_merge($key, $values, ['created_at' => now(), 'updated_at' => now()]));
            }

            return DB::table($table)->where($key)->value('id');
        };

        // ── Location Punta Arenas ──────────────────────────────────
        $locPuntaArenas = $upsert('locations', [
            'name' => 'Kinesilk Punta Arenas',
            'address' => 'Av. Magallanes 500',
        ], [
            'city' => 'Punta Arenas',
            'timezone' => 'America/Punta_Arenas',
            'active' => true,
            'opening_time' => '09:00:00',
            'closing_time' => '19:00:00',
        ]);

        $this->command?->info("Punta Arenas location ID: {$locPuntaArenas}");

        // ── Provider ───────────────────────────────────────────────
        $providerId = $upsert('providers', ['email' => 'francisco@kinesilk.cl'], [
            'first_name' => 'Francisco',
            'last_name' => 'Mardones',
            'phone' => '+56912345100',
            'location_id' => $locPuntaArenas,
            'active' => true,
        ]);

        $this->command?->info("Provider Francisco Mardones ID: {$providerId}");

        // ── Provider ↔ Service (asignar a todos los servicios activos) ─
        $services = DB::table('services')->where('active', true)->pluck('id');

        foreach ($services as $serviceId) {
            DB::table('provider_service')->insertOrIgnore([
                'provider_id' => $providerId,
                'service_id' => $serviceId,
            ]);
        }

        // ── User para el provider ─────────────────────────────────
        $upsert('users', ['email' => 'francisco@kinesilk.cl'], [
            'name' => 'Francisco Mardones',
            'password' => Hash::make('password'),
            'role' => 'provider',
            'provider_id' => $providerId,
        ]);

        $this->command?->info('User for Francisco Mardones upserted.');
    }
}
