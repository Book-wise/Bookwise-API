<?php

namespace Tests\Feature\Api\V1;

use App\Models\Comuna;
use App\Models\Region;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class RegionControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    // ── GET /v1/regions (task 2.10) ───────────────────────────────

    public function test_regions_returns_all_16_sorted_by_sort_order(): void
    {
        $regions = [
            ['name' => 'Arica y Parinacota', 'timezone' => 'America/Santiago', 'sort_order' => 1],
            ['name' => 'Tarapacá', 'timezone' => 'America/Santiago', 'sort_order' => 2],
            ['name' => 'Antofagasta', 'timezone' => 'America/Santiago', 'sort_order' => 3],
            ['name' => 'Atacama', 'timezone' => 'America/Santiago', 'sort_order' => 4],
            ['name' => 'Coquimbo', 'timezone' => 'America/Santiago', 'sort_order' => 5],
            ['name' => 'Valparaíso', 'timezone' => 'America/Santiago', 'sort_order' => 6],
            ['name' => 'Metropolitana', 'timezone' => 'America/Santiago', 'sort_order' => 7],
            ['name' => "Libertador Gral. Bernardo O'Higgins", 'timezone' => 'America/Santiago', 'sort_order' => 8],
            ['name' => 'Maule', 'timezone' => 'America/Santiago', 'sort_order' => 9],
            ['name' => 'Ñuble', 'timezone' => 'America/Santiago', 'sort_order' => 10],
            ['name' => 'Biobío', 'timezone' => 'America/Santiago', 'sort_order' => 11],
            ['name' => 'La Araucanía', 'timezone' => 'America/Santiago', 'sort_order' => 12],
            ['name' => 'Los Ríos', 'timezone' => 'America/Santiago', 'sort_order' => 13],
            ['name' => 'Los Lagos', 'timezone' => 'America/Santiago', 'sort_order' => 14],
            ['name' => 'Aysén del Gral. Carlos Ibáñez del Campo', 'timezone' => 'America/Santiago', 'sort_order' => 15],
            ['name' => 'Magallanes y de la Antártica Chilena', 'timezone' => 'America/Punta_Arenas', 'sort_order' => 16],
        ];

        foreach ($regions as $data) {
            Region::create($data);
        }

        $response = $this->getJson('/api/v1/regions');

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(16, $data);

        // Verify sorted by sort_order
        $this->assertSame('Arica y Parinacota', $data[0]['name']);
        $this->assertSame('Magallanes y de la Antártica Chilena', $data[15]['name']);

        // Verify all have timezone
        $this->assertArrayHasKey('timezone', $data[0]);
        $this->assertSame('America/Santiago', $data[6]['timezone']);
        $this->assertSame('America/Punta_Arenas', $data[15]['timezone']);
    }

    public function test_regions_is_public_no_auth_required(): void
    {
        Region::create([
            'name' => 'Metropolitana',
            'timezone' => 'America/Santiago',
            'sort_order' => 1,
        ]);

        $response = $this->getJson('/api/v1/regions');

        $response->assertStatus(200);
    }

    // ── GET /v1/regions/{id}/comunas (task 2.11) ──────────────────

    public function test_region_comunas_returns_only_that_regions_comunas(): void
    {
        $rm = Region::create(['name' => 'Metropolitana', 'timezone' => 'America/Santiago', 'sort_order' => 7]);
        $valpo = Region::create(['name' => 'Valparaíso', 'timezone' => 'America/Santiago', 'sort_order' => 6]);

        Comuna::create(['region_id' => $rm->id, 'name' => 'Santiago']);
        Comuna::create(['region_id' => $rm->id, 'name' => 'Providencia']);
        Comuna::create(['region_id' => $valpo->id, 'name' => 'Viña del Mar']);

        $response = $this->getJson("/api/v1/regions/{$rm->id}/comunas");

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(2, $data);

        $names = array_map(fn ($c) => $c['name'], $data);
        $this->assertContains('Santiago', $names);
        $this->assertContains('Providencia', $names);
        $this->assertNotContains('Viña del Mar', $names);
    }

    public function test_region_comunas_returns_empty_array_when_region_has_no_comunas(): void
    {
        $region = Region::create(['name' => 'Aysén', 'timezone' => 'America/Santiago', 'sort_order' => 15]);

        $response = $this->getJson("/api/v1/regions/{$region->id}/comunas");

        $response->assertStatus(200);
        $this->assertEmpty($response->json('data'));
    }

    public function test_region_comunas_is_public_no_auth_required(): void
    {
        $region = Region::create(['name' => 'Metropolitana', 'timezone' => 'America/Santiago', 'sort_order' => 1]);

        $response = $this->getJson("/api/v1/regions/{$region->id}/comunas");

        $response->assertStatus(200);
    }
}
