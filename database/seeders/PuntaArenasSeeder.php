<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class PuntaArenasSeeder extends Seeder
{
    public function run(): void
    {
        // ── Location Punta Arenas ──────────────────────────────────
        $locPuntaArenas = DB::table('locations')->insertGetId([
            'name' => 'Kinesilk Punta Arenas',
            'address' => 'Av. Magallanes 500',
            'city' => 'Punta Arenas',
            'timezone' => 'America/Punta_Arenas',
            'active' => true,
            'opening_time' => '09:00:00',
            'closing_time' => '19:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->command?->info("Punta Arenas location created with ID: {$locPuntaArenas}");

        // ── Provider ───────────────────────────────────────────────
        $providerId = DB::table('providers')->insertGetId([
            'first_name' => 'Francisco',
            'last_name' => 'Mardones',
            'email' => 'francisco@kinesilk.cl',
            'phone' => '+56912345100',
            'location_id' => $locPuntaArenas,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->command?->info("Provider Francisco Mardones created with ID: {$providerId}");

        // ── Provider ↔ Service (asignar a todos los servicios activos) ─
        $services = DB::table('services')->where('active', true)->pluck('id');

        foreach ($services as $serviceId) {
            DB::table('provider_service')->insert([
                'provider_id' => $providerId,
                'service_id' => $serviceId,
            ]);
        }

        // ── User para el provider ─────────────────────────────────ー
        DB::table('users')->insert([
            'name' => 'Francisco Mardones',
            'email' => 'francisco@kinesilk.cl',
            'password' => Hash::make('password'),
            'role' => 'provider',
            'provider_id' => $providerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->command?->info('User for Francisco Mardones created.');
    }
}
