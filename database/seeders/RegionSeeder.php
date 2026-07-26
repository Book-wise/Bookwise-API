<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RegionSeeder extends Seeder
{
    public function run(): void
    {
        $regions = [
            ['name' => 'Arica y Parinacota',        'timezone' => 'America/Santiago',     'sort_order' => 1],
            ['name' => 'Tarapacá',                   'timezone' => 'America/Santiago',     'sort_order' => 2],
            ['name' => 'Antofagasta',                'timezone' => 'America/Santiago',     'sort_order' => 3],
            ['name' => 'Atacama',                    'timezone' => 'America/Santiago',     'sort_order' => 4],
            ['name' => 'Coquimbo',                   'timezone' => 'America/Santiago',     'sort_order' => 5],
            ['name' => 'Valparaíso',                 'timezone' => 'America/Santiago',     'sort_order' => 6],
            ['name' => 'Metropolitana',              'timezone' => 'America/Santiago',     'sort_order' => 7],
            ['name' => "Libertador Gral. Bernardo O'Higgins", 'timezone' => 'America/Santiago', 'sort_order' => 8],
            ['name' => 'Maule',                      'timezone' => 'America/Santiago',     'sort_order' => 9],
            ['name' => 'Ñuble',                      'timezone' => 'America/Santiago',     'sort_order' => 10],
            ['name' => 'Biobío',                     'timezone' => 'America/Santiago',     'sort_order' => 11],
            ['name' => 'La Araucanía',               'timezone' => 'America/Santiago',     'sort_order' => 12],
            ['name' => 'Los Ríos',                   'timezone' => 'America/Santiago',     'sort_order' => 13],
            ['name' => 'Los Lagos',                  'timezone' => 'America/Santiago',     'sort_order' => 14],
            ['name' => 'Aysén del Gral. Carlos Ibáñez del Campo', 'timezone' => 'America/Santiago',     'sort_order' => 15],
            ['name' => 'Magallanes y de la Antártica Chilena',    'timezone' => 'America/Punta_Arenas', 'sort_order' => 16],
        ];

        $now = now();
        $data = array_map(fn ($r) => $r + ['created_at' => $now, 'updated_at' => $now], $regions);

        DB::table('regions')->insert($data);

        $this->command?->info('Seeded '.count($regions).' Chilean regions.');
    }
}
