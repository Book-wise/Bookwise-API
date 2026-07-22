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

## Prueba de concurrencia MySQL

`tests/Integration/MySqlBookingConcurrencyTest.php` no usa SQLite ni forma parte de la validación por defecto. Ejecuta dos procesos PHP independientes contra el kernel HTTP real, los libera mediante una barrera determinista de archivos y repite 20 veces cada escenario de reservas incompatibles, adyacentes, reprogramaciones, bloqueos de agenda y ubicaciones independientes.

Sólo se habilita de forma explícita y borra/reconstruye su esquema antes de cada escenario. Usar exclusivamente una base desechable cuyo nombre empiece con `bookwise_api_concurrency_`; el test se niega a correr con cualquier otro nombre.

```bash
export APP_ENV=testing
export BOOKWISE_MYSQL_CONCURRENCY_TEST=1
export DB_CONNECTION=mysql
export DB_DATABASE=bookwise_api_concurrency_local
# Configurar host, puerto y credenciales mediante el mecanismo seguro del entorno.

php artisan test tests/Integration/MySqlBookingConcurrencyTest.php
```

Requiere MySQL/InnoDB y la extensión PHP `pcntl`. No ejecutar este comando contra una base compartida, persistente o de producción.
