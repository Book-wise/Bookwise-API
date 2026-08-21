<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RegionSeeder extends Seeder
{
    public function run(): void
    {
        // Helper idempotente: clave natural = id fijo (el front/docs referencian
        // regiones por id; ComunaSeeder apunta a region_id 1-16). Reinserción no duplica.
        $upsert = function (string $table, array $key, array $values): int {
            if (DB::table($table)->where($key)->exists()) {
                DB::table($table)->where($key)->update(array_merge($values, ['updated_at' => now()]));
            } else {
                DB::table($table)->insert(array_merge($key, $values, ['created_at' => now(), 'updated_at' => now()]));
            }

            return DB::table($table)->where($key)->value('id');
        };

        $regions = [
            ['id' => 1,  'name' => 'Arica y Parinacota',                                                    'timezone' => 'America/Santiago',     'sort_order' => 1],
            ['id' => 2,  'name' => 'Tarapacá',                                                              'timezone' => 'America/Santiago',     'sort_order' => 2],
            ['id' => 3,  'name' => 'Antofagasta',                                                           'timezone' => 'America/Santiago',     'sort_order' => 3],
            ['id' => 4,  'name' => 'Atacama',                                                               'timezone' => 'America/Santiago',     'sort_order' => 4],
            ['id' => 5,  'name' => 'Coquimbo',                                                              'timezone' => 'America/Santiago',     'sort_order' => 5],
            ['id' => 6,  'name' => 'Valparaíso',                                                            'timezone' => 'America/Santiago',     'sort_order' => 6],
            ['id' => 7,  'name' => 'Metropolitana',                                                         'timezone' => 'America/Santiago',     'sort_order' => 7],
            ['id' => 8,  'name' => "Libertador Gral. Bernardo O'Higgins",                                  'timezone' => 'America/Santiago',     'sort_order' => 8],
            ['id' => 9,  'name' => 'Maule',                                                                 'timezone' => 'America/Santiago',     'sort_order' => 9],
            ['id' => 10, 'name' => 'Ñuble',                                                                 'timezone' => 'America/Santiago',     'sort_order' => 10],
            ['id' => 11, 'name' => 'Biobío',                                                                'timezone' => 'America/Santiago',     'sort_order' => 11],
            ['id' => 12, 'name' => 'La Araucanía',                                                          'timezone' => 'America/Santiago',     'sort_order' => 12],
            ['id' => 13, 'name' => 'Los Ríos',                                                              'timezone' => 'America/Santiago',     'sort_order' => 13],
            ['id' => 14, 'name' => 'Los Lagos',                                                             'timezone' => 'America/Santiago',     'sort_order' => 14],
            ['id' => 15, 'name' => 'Aysén del Gral. Carlos Ibáñez del Campo',                              'timezone' => 'America/Santiago',     'sort_order' => 15],
            ['id' => 16, 'name' => 'Magallanes y de la Antártica Chilena',                                 'timezone' => 'America/Punta_Arenas', 'sort_order' => 16],
        ];

        foreach ($regions as $region) {
            $id = $region['id'];
            $upsert('regions', ['id' => $id], array_diff_key($region, ['id' => true]));
        }

        $this->command?->info('Seeded '.count($regions).' Chilean regions (idempotent).');
    }
}
