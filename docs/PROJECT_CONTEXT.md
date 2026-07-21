# Contexto local: Bookwise API

Esta ficha describe el checkout disponible y se actualiza tras verificar código, Git y pruebas; no reemplaza documentación del partner.

## Hechos verificados en el checkout

- `composer.json` declara PHP 8.3, Laravel 13, Laravel Sanctum, Eloquent, Spatie Query Builder y Guzzle.
- El backend declara PHPUnit 12 y Laravel Pint para validación.
- Sus assets internos usan Vite 8 y Tailwind CSS 4.
- La configuración local por defecto declara SQLite; los ambientes reales deben definir y verificar explícitamente su base de datos, esperada como MySQL 8 para el objetivo de despliegue.

## Operación

Antes de cambios revisar rutas, Form Requests, Resources, Services, Policies, middleware, modelos, migraciones, transacciones, CORS, Sanctum, colas, scheduler, webhooks y pruebas. Los comandos candidatos son `composer install`, `php artisan test`, `./vendor/bin/pint --test`, `php artisan route:list` y `php artisan config:show`.

No ejecutar migraciones, seeders, workers ni scheduler contra entornos reales sin aprobación explícita. No modificar `.env` reales, no usar `env()` fuera de configuración y no imprimir secretos. Todo cambio de contrato exige analizar impacto en frontend, WooCommerce y Carlitox.
