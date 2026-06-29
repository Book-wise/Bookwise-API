<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [
        'http://localhost',
        'http://localhost:3000',
        'http://localhost:4200',
        'http://127.0.0.1:9999',
        'http://localhost:64614',
        'http://kinesilk.local',
        'https://kinesilk.cl',
        'https://www.kinesilk.cl',
    ],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [
        'X-RateLimit-Limit',
        'X-RateLimit-Remaining',
        'Retry-After',
    ],
    'max_age' => 0,
    'supports_credentials' => false,
];
