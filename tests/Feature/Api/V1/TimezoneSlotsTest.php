<?php

namespace Tests\Feature\Api\V1;

use App\Models\Location;
use App\Models\Service;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class TimezoneSlotsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Location $puntaArenas;

    private Location $santiago;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Punta Arenas: UTC-3 fijo todo el año, opening 09:00 → 12:00 UTC siempre
        $this->puntaArenas = Location::create([
            'name' => 'Kinesilk Punta Arenas',
            'address' => 'Av. Magallanes 500',
            'city' => 'Punta Arenas',
            'timezone' => 'America/Punta_Arenas',
            'active' => true,
            'opening_time' => '09:00:00',
            'closing_time' => '19:00:00',
        ]);

        // Santiago: DST UTC-3/UTC-4
        $this->santiago = Location::create([
            'name' => 'Kinesilk Santiago Centro',
            'address' => 'Av. Providencia 1234',
            'city' => 'Santiago',
            'timezone' => 'America/Santiago',
            'active' => true,
            'opening_time' => '09:00:00',
            'closing_time' => '19:00:00',
        ]);

        $this->service = Service::create([
            'name' => 'Masaje Test',
            'duration_minutes' => 60,
            'price' => 35000,
            'active' => true,
        ]);
    }

    // ── Punta Arenas (UTC-3 fijo) ─────────────────────────────────

    public function test_punta_arenas_slot_offset_utc_minus_3(): void
    {
        // Usamos junio (invierno en Chile) — Punta Arenas sigue UTC-3
        $response = $this->getJson('/api/v1/available_slots', [
            'location_id' => $this->puntaArenas->id,
            'start_date' => '2026-06-15',
            'service_id' => $this->service->id,
        ]);

        // El test anterior no manda query params; es GET con query string
        $response = $this->getJson(
            '/api/v1/available_slots?location_id='.$this->puntaArenas->id
            .'&start_date=2026-06-15'
            .'&service_id='.$this->service->id
        );

        $response->assertStatus(200);

        $slots = $response->json('data');
        $this->assertNotEmpty($slots);

        // Primer slot: 09:00 Punta Arenas = 12:00 UTC
        $firstSlot = $slots[0];
        $this->assertStringStartsWith('2026-06-15T12:00', $firstSlot['start']);
        $this->assertSame(60, $firstSlot['duration_minutes']);
    }

    public function test_punta_arenas_slot_offset_in_summer(): void
    {
        // Enero (verano) — Punta Arenas sigue UTC-3
        $response = $this->getJson(
            '/api/v1/available_slots?location_id='.$this->puntaArenas->id
            .'&start_date=2026-01-15'
            .'&service_id='.$this->service->id
        );

        $response->assertStatus(200);

        $slots = $response->json('data');
        $this->assertNotEmpty($slots);

        // Primer slot: 09:00 Punta Arenas = 12:00 UTC (sin DST)
        $firstSlot = $slots[0];
        $this->assertStringStartsWith('2026-01-15T12:00', $firstSlot['start']);
    }

    // ── Santiago (DST UTC-3 ↔ UTC-4) ──────────────────────────────

    public function test_santiago_winter_slot_offset_utc_minus_4(): void
    {
        // Junio (invierno): Santiago UTC-4 → 09:00 local = 13:00 UTC
        $response = $this->getJson(
            '/api/v1/available_slots?location_id='.$this->santiago->id
            .'&start_date=2026-06-15'
            .'&service_id='.$this->service->id
        );

        $response->assertStatus(200);

        $slots = $response->json('data');
        $this->assertNotEmpty($slots);

        // Primer slot: 09:00 Santiago (invierno) = 13:00 UTC
        $firstSlot = $slots[0];
        $this->assertStringStartsWith('2026-06-15T13:00', $firstSlot['start']);
    }

    public function test_santiago_summer_slot_offset_utc_minus_3(): void
    {
        // Enero (verano): Santiago UTC-3 → 09:00 local = 12:00 UTC
        $response = $this->getJson(
            '/api/v1/available_slots?location_id='.$this->santiago->id
            .'&start_date=2026-01-15'
            .'&service_id='.$this->service->id
        );

        $response->assertStatus(200);

        $slots = $response->json('data');
        $this->assertNotEmpty($slots);

        // Primer slot: 09:00 Santiago (verano) = 12:00 UTC
        $firstSlot = $slots[0];
        $this->assertStringStartsWith('2026-01-15T12:00', $firstSlot['start']);
    }

    // ── Diferencia Santiago vs Punta Arenas en invierno ────────────

    public function test_winter_difference_between_santiago_and_punta_arenas(): void
    {
        // Junio: Santiago UTC-4, Punta Arenas UTC-3
        // 09:00 local en Santiago = 13:00 UTC
        // 09:00 local en Punta Arenas = 12:00 UTC
        // Diferencia de 1 hora

        $santiagoSlots = $this->getJson(
            '/api/v1/available_slots?location_id='.$this->santiago->id
            .'&start_date=2026-06-15'
            .'&service_id='.$this->service->id
        )->json('data');

        $puntaSlots = $this->getJson(
            '/api/v1/available_slots?location_id='.$this->puntaArenas->id
            .'&start_date=2026-06-15'
            .'&service_id='.$this->service->id
        )->json('data');

        $this->assertNotEmpty($santiagoSlots);
        $this->assertNotEmpty($puntaSlots);

        // Misma hora local (09:00) → diferente UTC
        $this->assertSame('2026-06-15T13:00:00+00:00', $santiagoSlots[0]['start']);
        $this->assertSame('2026-06-15T12:00:00+00:00', $puntaSlots[0]['start']);
    }
}
