<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ComunaSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        // ── Region 7: Metropolitana ─────────────────────────────────
        $rmComunas = [
            'Cerrillos', 'Cerro Navia', 'Conchalí', 'El Bosque',
            'Estación Central', 'Huechuraba', 'Independencia', 'La Cisterna',
            'La Florida', 'La Granja', 'La Pintana', 'La Reina',
            'Las Condes', 'Lo Barnechea', 'Lo Espejo', 'Lo Prado',
            'Macul', 'Maipú', 'Ñuñoa', 'Pedro Aguirre Cerda',
            'Peñalolén', 'Providencia', 'Pudahuel', 'Quilicura',
            'Quinta Normal', 'Recoleta', 'Renca', 'San Joaquín',
            'San Miguel', 'San Ramón', 'Santiago', 'Vitacura',
            'Colina', 'Lampa', 'Til Til', 'Pirque',
            'Puente Alto', 'San José de Maipo', 'Buin', 'Calera de Tango',
            'Paine', 'San Bernardo', 'Alhué', 'Curacaví',
            'María Pinto', 'Melipilla', 'San Pedro', 'Talagante',
            'El Monte', 'Isla de Maipo', 'Padre Hurtado', 'Peñaflor',
        ];

        foreach ($rmComunas as $name) {
            DB::table('comunas')->insert([
                'region_id' => 7,
                'name' => $name,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // ── Region 6: Valparaíso ────────────────────────────────────
        $valpoComunas = [
            'Valparaíso', 'Viña del Mar', 'Concón', 'Quintero',
            'Villa Alemana', 'Quilpué', 'Limache', 'Olmué',
            'Los Andes', 'San Felipe', 'San Antonio', 'Cartagena',
        ];

        foreach ($valpoComunas as $name) {
            DB::table('comunas')->insert([
                'region_id' => 6,
                'name' => $name,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // ── Region 8: O'Higgins ─────────────────────────────────────
        $ohigginsComunas = [
            'Rancagua', 'Machalí', 'San Fernando', 'Rengo',
            'Santa Cruz', 'Pichilemu', 'Graneros', 'Codegua',
        ];

        foreach ($ohigginsComunas as $name) {
            DB::table('comunas')->insert([
                'region_id' => 8,
                'name' => $name,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // ── Region 16: Magallanes ───────────────────────────────────
        $magallanesComunas = [
            'Punta Arenas', 'Puerto Natales', 'Porvenir',
        ];

        foreach ($magallanesComunas as $name) {
            DB::table('comunas')->insert([
                'region_id' => 16,
                'name' => $name,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $count = count($rmComunas) + count($valpoComunas) + count($ohigginsComunas) + count($magallanesComunas);
        $this->command?->info("Seeded {$count} comunas across 4 regions.");
    }
}
