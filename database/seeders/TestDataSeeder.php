<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class TestDataSeeder extends Seeder
{
    public function run(): void
    {
        // Location
        DB::table('locations')->insert([
            'name'      => 'Kinesilk Centro',
            'address'   => 'Av. Providencia 1234',
            'city'      => 'Santiago',
            'timezone'  => 'America/Santiago',
            'active'    => true,
            'created_at'=> now(),
            'updated_at'=> now(),
        ]);

        // Service
        DB::table('services')->insert([
            'name'                  => 'Masaje Relajante',
            'duration_minutes'      => 60,
            'slot_interval_minutes' => 15,
            'min_duration_minutes'  => 30,
            'max_duration_minutes'  => 120,
            'price'                 => 35000,
            'active'                => true,
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        // Provider
        $providerId = DB::table('providers')->insertGetId([
            'first_name' => 'María',
            'last_name'  => 'González',
            'email'      => 'maria@kinesilk.cl',
            'active'     => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Users
        DB::table('users')->insert([
            [
                'name'        => 'Admin Kinesilk',
                'email'       => 'admin@kinesilk.cl',
                'password'    => Hash::make('password'),
                'role'        => 'admin',
                'provider_id' => null,
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
            [
                'name'        => 'María González',
                'email'       => 'maria@kinesilk.cl',
                'password'    => Hash::make('password'),
                'role'        => 'provider',
                'provider_id' => $providerId,
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
        ]);

        // Booking statuses — IDs match frontend STATUS_COLOR_MAP (1-6) + Cancelada (7)
        DB::table('booking_statuses')->insert([
            ['id' => 1, 'name' => 'Reservado',   'color' => '#93c5fd', 'is_cancellation' => false, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Confirmado',  'color' => '#fb923c', 'is_cancellation' => false, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'name' => 'Asiste',      'color' => '#ec4899', 'is_cancellation' => false, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 4, 'name' => 'No asistio',  'color' => '#f9a8d4', 'is_cancellation' => false, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 5, 'name' => 'Pendiente',   'color' => '#fca5a5', 'is_cancellation' => false, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 6, 'name' => 'En espera',   'color' => '#86efac', 'is_cancellation' => false, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 7, 'name' => 'Cancelada',   'color' => '#d1d5db', 'is_cancellation' => true,  'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}
