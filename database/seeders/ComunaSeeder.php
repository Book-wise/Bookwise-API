<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ComunaSeeder extends Seeder
{
    public function run(): void
    {
        // Helper idempotente: clave natural (region_id, name). Reinserción no duplica.
        $upsert = function (string $table, array $key, array $values): int {
            if (DB::table($table)->where($key)->exists()) {
                DB::table($table)->where($key)->update(array_merge($values, ['updated_at' => now()]));
            } else {
                DB::table($table)->insert(array_merge($key, $values, ['created_at' => now(), 'updated_at' => now()]));
            }

            return DB::table($table)->where($key)->value('id');
        };

        $all = [];

        // ── Region 1: Arica y Parinacota ────────────────────────────
        $all[] = ['region_id' => 1, 'name' => 'Arica'];
        $all[] = ['region_id' => 1, 'name' => 'Camarones'];
        $all[] = ['region_id' => 1, 'name' => 'Putre'];
        $all[] = ['region_id' => 1, 'name' => 'General Lagos'];

        // ── Region 2: Tarapacá ──────────────────────────────────────
        $all[] = ['region_id' => 2, 'name' => 'Iquique'];
        $all[] = ['region_id' => 2, 'name' => 'Alto Hospicio'];
        $all[] = ['region_id' => 2, 'name' => 'Pozo Almonte'];
        $all[] = ['region_id' => 2, 'name' => 'Camiña'];
        $all[] = ['region_id' => 2, 'name' => 'Colchane'];
        $all[] = ['region_id' => 2, 'name' => 'Huara'];
        $all[] = ['region_id' => 2, 'name' => 'Pica'];

        // ── Region 3: Antofagasta ───────────────────────────────────
        $all[] = ['region_id' => 3, 'name' => 'Antofagasta'];
        $all[] = ['region_id' => 3, 'name' => 'Mejillones'];
        $all[] = ['region_id' => 3, 'name' => 'Sierra Gorda'];
        $all[] = ['region_id' => 3, 'name' => 'Taltal'];
        $all[] = ['region_id' => 3, 'name' => 'Calama'];
        $all[] = ['region_id' => 3, 'name' => 'Ollagüe'];
        $all[] = ['region_id' => 3, 'name' => 'San Pedro de Atacama'];
        $all[] = ['region_id' => 3, 'name' => 'Tocopilla'];
        $all[] = ['region_id' => 3, 'name' => 'María Elena'];

        // ── Region 4: Atacama ───────────────────────────────────────
        $all[] = ['region_id' => 4, 'name' => 'Copiapó'];
        $all[] = ['region_id' => 4, 'name' => 'Caldera'];
        $all[] = ['region_id' => 4, 'name' => 'Tierra Amarilla'];
        $all[] = ['region_id' => 4, 'name' => 'Chañaral'];
        $all[] = ['region_id' => 4, 'name' => 'Diego de Almagro'];
        $all[] = ['region_id' => 4, 'name' => 'Vallenar'];
        $all[] = ['region_id' => 4, 'name' => 'Alto del Carmen'];
        $all[] = ['region_id' => 4, 'name' => 'Freirina'];
        $all[] = ['region_id' => 4, 'name' => 'Huasco'];

        // ── Region 5: Coquimbo ──────────────────────────────────────
        $all[] = ['region_id' => 5, 'name' => 'La Serena'];
        $all[] = ['region_id' => 5, 'name' => 'Coquimbo'];
        $all[] = ['region_id' => 5, 'name' => 'Andacollo'];
        $all[] = ['region_id' => 5, 'name' => 'La Higuera'];
        $all[] = ['region_id' => 5, 'name' => 'Paiguano'];
        $all[] = ['region_id' => 5, 'name' => 'Vicuña'];
        $all[] = ['region_id' => 5, 'name' => 'Illapel'];
        $all[] = ['region_id' => 5, 'name' => 'Canela'];
        $all[] = ['region_id' => 5, 'name' => 'Los Vilos'];
        $all[] = ['region_id' => 5, 'name' => 'Salamanca'];
        $all[] = ['region_id' => 5, 'name' => 'Ovalle'];
        $all[] = ['region_id' => 5, 'name' => 'Combarbalá'];
        $all[] = ['region_id' => 5, 'name' => 'Monte Patria'];
        $all[] = ['region_id' => 5, 'name' => 'Punitaqui'];
        $all[] = ['region_id' => 5, 'name' => 'Río Hurtado'];

        // ── Region 6: Valparaíso ────────────────────────────────────
        $all[] = ['region_id' => 6, 'name' => 'Valparaíso'];
        $all[] = ['region_id' => 6, 'name' => 'Viña del Mar'];
        $all[] = ['region_id' => 6, 'name' => 'Concón'];
        $all[] = ['region_id' => 6, 'name' => 'Quintero'];
        $all[] = ['region_id' => 6, 'name' => 'Villa Alemana'];
        $all[] = ['region_id' => 6, 'name' => 'Quilpué'];
        $all[] = ['region_id' => 6, 'name' => 'Limache'];
        $all[] = ['region_id' => 6, 'name' => 'Olmué'];
        $all[] = ['region_id' => 6, 'name' => 'Los Andes'];
        $all[] = ['region_id' => 6, 'name' => 'San Felipe'];
        $all[] = ['region_id' => 6, 'name' => 'San Antonio'];
        $all[] = ['region_id' => 6, 'name' => 'Cartagena'];
        $all[] = ['region_id' => 6, 'name' => 'Juan Fernández'];
        $all[] = ['region_id' => 6, 'name' => 'Catemu'];
        $all[] = ['region_id' => 6, 'name' => 'Calle Larga'];
        $all[] = ['region_id' => 6, 'name' => 'Rinconada'];
        $all[] = ['region_id' => 6, 'name' => 'Algarrobo'];
        $all[] = ['region_id' => 6, 'name' => 'El Quisco'];
        $all[] = ['region_id' => 6, 'name' => 'El Tabo'];
        $all[] = ['region_id' => 6, 'name' => 'Santo Domingo'];

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
            $all[] = ['region_id' => 7, 'name' => $name];
        }

        // ── Region 8: O'Higgins ─────────────────────────────────────
        $all[] = ['region_id' => 8, 'name' => 'Rancagua'];
        $all[] = ['region_id' => 8, 'name' => 'Machalí'];
        $all[] = ['region_id' => 8, 'name' => 'San Fernando'];
        $all[] = ['region_id' => 8, 'name' => 'Rengo'];
        $all[] = ['region_id' => 8, 'name' => 'Santa Cruz'];
        $all[] = ['region_id' => 8, 'name' => 'Pichilemu'];
        $all[] = ['region_id' => 8, 'name' => 'Graneros'];
        $all[] = ['region_id' => 8, 'name' => 'Codegua'];
        $all[] = ['region_id' => 8, 'name' => 'Mostazal'];
        $all[] = ['region_id' => 8, 'name' => 'San Vicente de Tagua Tagua'];
        $all[] = ['region_id' => 8, 'name' => 'Pichidegua'];
        $all[] = ['region_id' => 8, 'name' => 'Peralillo'];

        // ── Region 9: Maule ─────────────────────────────────────────
        $all[] = ['region_id' => 9, 'name' => 'Talca'];
        $all[] = ['region_id' => 9, 'name' => 'Curicó'];
        $all[] = ['region_id' => 9, 'name' => 'Linares'];
        $all[] = ['region_id' => 9, 'name' => 'Constitución'];
        $all[] = ['region_id' => 9, 'name' => 'Maule'];
        $all[] = ['region_id' => 9, 'name' => 'San Javier'];
        $all[] = ['region_id' => 9, 'name' => 'Parral'];
        $all[] = ['region_id' => 9, 'name' => 'Cauquenes'];
        $all[] = ['region_id' => 9, 'name' => 'Molina'];
        $all[] = ['region_id' => 9, 'name' => 'Teno'];
        $all[] = ['region_id' => 9, 'name' => 'Romeral'];
        $all[] = ['region_id' => 9, 'name' => 'Rauco'];
        $all[] = ['region_id' => 9, 'name' => 'Pelarco'];
        $all[] = ['region_id' => 9, 'name' => 'San Clemente'];
        $all[] = ['region_id' => 9, 'name' => 'Longaví'];
        $all[] = ['region_id' => 9, 'name' => 'Retiro'];

        // ── Region 10: Ñuble ────────────────────────────────────────
        $all[] = ['region_id' => 10, 'name' => 'Chillán'];
        $all[] = ['region_id' => 10, 'name' => 'Chillán Viejo'];
        $all[] = ['region_id' => 10, 'name' => 'San Carlos'];
        $all[] = ['region_id' => 10, 'name' => 'Bulnes'];
        $all[] = ['region_id' => 10, 'name' => 'Coihueco'];
        $all[] = ['region_id' => 10, 'name' => 'Quirihue'];
        $all[] = ['region_id' => 10, 'name' => 'Yungay'];
        $all[] = ['region_id' => 10, 'name' => 'Pemuco'];
        $all[] = ['region_id' => 10, 'name' => 'Ñiquén'];
        $all[] = ['region_id' => 10, 'name' => 'San Fabián'];
        $all[] = ['region_id' => 10, 'name' => 'El Carmen'];
        $all[] = ['region_id' => 10, 'name' => 'Pinto'];

        // ── Region 11: Biobío ───────────────────────────────────────
        $all[] = ['region_id' => 11, 'name' => 'Concepción'];
        $all[] = ['region_id' => 11, 'name' => 'Talcahuano'];
        $all[] = ['region_id' => 11, 'name' => 'Hualpén'];
        $all[] = ['region_id' => 11, 'name' => 'San Pedro de la Paz'];
        $all[] = ['region_id' => 11, 'name' => 'Chiguayante'];
        $all[] = ['region_id' => 11, 'name' => 'Coronel'];
        $all[] = ['region_id' => 11, 'name' => 'Lota'];
        $all[] = ['region_id' => 11, 'name' => 'Tomé'];
        $all[] = ['region_id' => 11, 'name' => 'Penco'];
        $all[] = ['region_id' => 11, 'name' => 'Hualqui'];
        $all[] = ['region_id' => 11, 'name' => 'Los Ángeles'];
        $all[] = ['region_id' => 11, 'name' => 'Laja'];
        $all[] = ['region_id' => 11, 'name' => 'Nacimiento'];
        $all[] = ['region_id' => 11, 'name' => 'Arauco'];
        $all[] = ['region_id' => 11, 'name' => 'Lebu'];
        $all[] = ['region_id' => 11, 'name' => 'Cañete'];
        $all[] = ['region_id' => 11, 'name' => 'Curanilahue'];
        $all[] = ['region_id' => 11, 'name' => 'Mulchén'];

        // ── Region 12: La Araucanía ─────────────────────────────────
        $all[] = ['region_id' => 12, 'name' => 'Temuco'];
        $all[] = ['region_id' => 12, 'name' => 'Padre Las Casas'];
        $all[] = ['region_id' => 12, 'name' => 'Villarrica'];
        $all[] = ['region_id' => 12, 'name' => 'Pucón'];
        $all[] = ['region_id' => 12, 'name' => 'Angol'];
        $all[] = ['region_id' => 12, 'name' => 'Lautaro'];
        $all[] = ['region_id' => 12, 'name' => 'Nueva Imperial'];
        $all[] = ['region_id' => 12, 'name' => 'Victoria'];
        $all[] = ['region_id' => 12, 'name' => 'Collipulli'];
        $all[] = ['region_id' => 12, 'name' => 'Freire'];
        $all[] = ['region_id' => 12, 'name' => 'Loncoche'];
        $all[] = ['region_id' => 12, 'name' => 'Gorbea'];
        $all[] = ['region_id' => 12, 'name' => 'Pitrufquén'];
        $all[] = ['region_id' => 12, 'name' => 'Carahue'];
        $all[] = ['region_id' => 12, 'name' => 'Cunco'];
        $all[] = ['region_id' => 12, 'name' => 'Purén'];
        $all[] = ['region_id' => 12, 'name' => 'Traiguén'];

        // ── Region 13: Los Ríos ─────────────────────────────────────
        $all[] = ['region_id' => 13, 'name' => 'Valdivia'];
        $all[] = ['region_id' => 13, 'name' => 'La Unión'];
        $all[] = ['region_id' => 13, 'name' => 'Río Bueno'];
        $all[] = ['region_id' => 13, 'name' => 'Paillaco'];
        $all[] = ['region_id' => 13, 'name' => 'Panguipulli'];
        $all[] = ['region_id' => 13, 'name' => 'Los Lagos'];
        $all[] = ['region_id' => 13, 'name' => 'Futrono'];
        $all[] = ['region_id' => 13, 'name' => 'Máfil'];
        $all[] = ['region_id' => 13, 'name' => 'Lanco'];
        $all[] = ['region_id' => 13, 'name' => 'Mariquina'];
        $all[] = ['region_id' => 13, 'name' => 'Corral'];

        // ── Region 14: Los Lagos ────────────────────────────────────
        $all[] = ['region_id' => 14, 'name' => 'Puerto Montt'];
        $all[] = ['region_id' => 14, 'name' => 'Puerto Varas'];
        $all[] = ['region_id' => 14, 'name' => 'Castro'];
        $all[] = ['region_id' => 14, 'name' => 'Ancud'];
        $all[] = ['region_id' => 14, 'name' => 'Osorno'];
        $all[] = ['region_id' => 14, 'name' => 'Llanquihue'];
        $all[] = ['region_id' => 14, 'name' => 'Frutillar'];
        $all[] = ['region_id' => 14, 'name' => 'Calbuco'];
        $all[] = ['region_id' => 14, 'name' => 'Maullín'];
        $all[] = ['region_id' => 14, 'name' => 'Chonchi'];
        $all[] = ['region_id' => 14, 'name' => 'Quellón'];
        $all[] = ['region_id' => 14, 'name' => 'Dalcahue'];
        $all[] = ['region_id' => 14, 'name' => 'Purranque'];
        $all[] = ['region_id' => 14, 'name' => 'Río Negro'];
        $all[] = ['region_id' => 14, 'name' => 'San Juan de la Costa'];
        $all[] = ['region_id' => 14, 'name' => 'San Pablo'];

        // ── Region 15: Aysén ────────────────────────────────────────
        $all[] = ['region_id' => 15, 'name' => 'Coyhaique'];
        $all[] = ['region_id' => 15, 'name' => 'Aysén'];
        $all[] = ['region_id' => 15, 'name' => 'Chile Chico'];
        $all[] = ['region_id' => 15, 'name' => 'Cochrane'];
        $all[] = ['region_id' => 15, 'name' => 'Puerto Aysén'];
        $all[] = ['region_id' => 15, 'name' => 'Río Ibáñez'];
        $all[] = ['region_id' => 15, 'name' => 'Tortel'];
        $all[] = ['region_id' => 15, 'name' => 'Guaitecas'];
        $all[] = ['region_id' => 15, 'name' => 'Lago Verde'];
        $all[] = ['region_id' => 15, 'name' => 'O\'Higgins'];

        // ── Region 16: Magallanes ───────────────────────────────────
        $all[] = ['region_id' => 16, 'name' => 'Punta Arenas'];
        $all[] = ['region_id' => 16, 'name' => 'Puerto Natales'];
        $all[] = ['region_id' => 16, 'name' => 'Porvenir'];
        $all[] = ['region_id' => 16, 'name' => 'Primavera'];
        $all[] = ['region_id' => 16, 'name' => 'Cabo de Hornos'];
        $all[] = ['region_id' => 16, 'name' => 'Laguna Blanca'];
        $all[] = ['region_id' => 16, 'name' => 'Río Verde'];
        $all[] = ['region_id' => 16, 'name' => 'San Gregorio'];
        $all[] = ['region_id' => 16, 'name' => 'Timaukel'];
        $all[] = ['region_id' => 16, 'name' => 'Torres del Paine'];

        // ── Upsert idempotente ──────────────────────────────────────
        foreach ($all as $comuna) {
            $upsert('comunas', ['region_id' => $comuna['region_id'], 'name' => $comuna['name']], []);
        }

        $this->command?->info('Seeded '.count($all).' comunas across all 16 regions (idempotent).');
    }
}
