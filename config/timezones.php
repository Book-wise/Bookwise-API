<?php

return [
    'default_timezone' => env('DEFAULT_TIMEZONE', 'America/Santiago'),

    'available' => [
        [
            'id' => 'America/Santiago',
            'name' => 'Santiago (UTC-3 / UTC-4)',
            'has_dst' => true,
        ],
        [
            'id' => 'America/Punta_Arenas',
            'name' => 'Punta Arenas (UTC-3 permanente)',
            'has_dst' => false,
        ],
    ],
];
