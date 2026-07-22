<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ConfigControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_config_returns_200_with_expected_structure(): void
    {
        $response = $this->getJson('/api/v1/config');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'default_timezone',
            'available_timezones' => [
                '*' => [
                    'id',
                    'name',
                    'has_dst',
                ],
            ],
        ]);
    }

    public function test_config_returns_default_timezone(): void
    {
        $response = $this->getJson('/api/v1/config');

        $response->assertStatus(200);
        $response->assertJsonPath('default_timezone', 'America/Santiago');
    }

    public function test_config_returns_both_available_timezones(): void
    {
        $response = $this->getJson('/api/v1/config');

        $response->assertStatus(200);
        $timezones = $response->json('available_timezones');

        $this->assertCount(2, $timezones);

        $this->assertSame('America/Santiago', $timezones[0]['id']);
        $this->assertTrue($timezones[0]['has_dst']);

        $this->assertSame('America/Punta_Arenas', $timezones[1]['id']);
        $this->assertFalse($timezones[1]['has_dst']);
    }

    public function test_config_is_public_no_auth_required(): void
    {
        $response = $this->getJson('/api/v1/config');

        $response->assertStatus(200);
    }
}
