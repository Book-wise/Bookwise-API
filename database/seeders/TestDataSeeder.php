<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

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
        DB::table('providers')->insert([
            'first_name' => 'María',
            'last_name'  => 'González',
            'email'      => 'maria@kinesilk.cl',
            'active'     => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Booking status
        DB::table('booking_statuses')->insert([
            ['name' => 'Confirmada', 'color' => '#1D9E75', 'is_cancellation' => false, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Cancelada',  'color' => '#D85A30', 'is_cancellation' => true,  'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}
