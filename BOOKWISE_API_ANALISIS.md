# BOOKWISE API - Analisis tecnico y funcional

Fecha de auditoria: 2026-07-06  
Repositorio auditado: `Bookwise-API`  
Alcance: analisis tecnico/funcional. No se implementaron cambios de codigo, no se desplego y no se modifico infraestructura.

## 1. Resumen ejecutivo

Bookwise API es una API REST construida en Laravel para gestionar agenda, clientes, profesionales, sedes, servicios, packs de sesiones, ventas y sincronizacion parcial con WooCommerce. El dominio visible en README y datos de prueba aparece como Kinesilk, una red de atencion kinesiologica/masajes en Chile.

El sistema implementa una base funcional relevante: login con Laravel Sanctum, roles, scopes por token, CRUD parcial de reservas y clientes, lectura de servicios/sedes/profesionales/packs, bloqueos horarios, calculo de slots disponibles, consumo de sesiones de packs, ventas de solo lectura y webhooks WooCommerce con firma HMAC.

El estado tecnico no esta listo para despliegue productivo sin trabajo previo. Hay divergencias entre README, frontend y backend; faltan endpoints usados por el frontend; no hay Docker, CI/CD ni scripts de despliegue; no se pudo ejecutar localmente porque el entorno no tiene `php`, `composer`, `vendor/`, `node_modules` ni `docker`; y hay riesgos funcionales/seguridad importantes, especialmente autorizacion por ownership, disponibilidad que no considera bloqueos, creacion de reservas que no valida bloqueos, secretos/credenciales de prueba en seeder y webhooks aceptables si `WC_WEBHOOK_SECRET` queda vacio.

## 2. Proposito funcional de la API

La API resuelve la operacion de una agenda clinica/comercial:

- Gestionar sedes (`locations`) con horario de apertura/cierre.
- Gestionar profesionales (`providers`) asociados a sedes y servicios.
- Gestionar servicios (`services`) con duracion, intervalo y precio.
- Gestionar clientes/pacientes (`clients`) con email, telefono, RUT, genero, direccion, notas y atributos personalizados.
- Crear, consultar, actualizar y cancelar reservas (`bookings`) con estado, precio, sede, servicio, profesional y cliente.
- Registrar ventas (`sales`) vinculadas a reservas, principalmente desde WooCommerce.
- Gestionar packs de sesiones (`service_packs`, `client_packs`, `pack_sessions`).
- Bloquear horarios completos, por sede o por profesional, con repeticion diaria/semanal/mensual.
- Recibir webhooks WooCommerce para confirmar/cancelar reservas y sincronizar clientes.

NO DETERMINADO: si Bookwise es el nombre comercial definitivo o si Kinesilk es el tenant/cliente inicial. El README, seeds y nombres de dominio usan Kinesilk; el workspace y frontend usan Bookwise.

## 3. Stack tecnologico

| Area | Estado verificado |
| --- | --- |
| Lenguaje backend | PHP, requerido por `composer.json` como `^8.3`. |
| Framework | Laravel `^13.0`; `composer.lock` fija `laravel/framework v13.7.0`. |
| Auth | Laravel Sanctum `v4.3.1`. |
| ORM | Eloquent. |
| DB por defecto | SQLite en `.env.example` y `config/database.php`. README menciona MySQL 8.0, pero no esta configurado por defecto. |
| Dependencias PHP | Composer. |
| Front/assets | Vite, Tailwind CSS 4, Laravel Vite plugin. |
| Testing | PHPUnit `12.5.x`; tests actuales son ejemplos minimos. |
| Rate limiting | Laravel RateLimiter en `AppServiceProvider`. |
| Integracion externa | WooCommerce REST API y webhooks. |

Divergencia importante: README declara Laravel 11 y PHP 8.2, pero los manifiestos actuales declaran Laravel 13 y PHP 8.3.

## 4. Arquitectura actual

Arquitectura monolitica Laravel, API versionada bajo `/api/v1`.

Capas visibles:

- `routes/api.php`: contrato HTTP y middlewares.
- `app/Http/Controllers/Api/V1`: controladores versionados.
- `app/Models`: modelos Eloquent.
- `app/Http/Resources/V1`: serializacion JSON.
- `app/Services`: logica de disponibilidad y WooCommerce.
- `app/Http/Middleware`: scope, role y ownership.
- `database/migrations`: schema.
- `database/seeders`: datos de prueba.

No hay `app/Http/Requests`, pese a que README menciona Form Requests. Las validaciones estan inline dentro de controladores.

Punto de entrada:

- HTTP publico: `public/index.php`.
- Bootstrap Laravel: `bootstrap/app.php`.
- Health Laravel: `/up`, configurado por `withRouting(... health: '/up')`.

## 5. Estructura del repositorio

| Ruta | Proposito |
| --- | --- |
| `app/Enums/UserRole.php` | Roles y abilities Sanctum por rol. |
| `app/Http/Controllers/Api/V1` | Endpoints REST. |
| `app/Http/Middleware` | Autorizacion por scope/rol/ownership. |
| `app/Http/Resources` y `app/Http/Resources/V1` | Recursos JSON; hay recursos antiguos y versionados. |
| `app/Models` | Entidades Eloquent. |
| `app/Rules/ChileanRutRule.php` | Validacion de RUT chileno. |
| `app/Services` | Disponibilidad y WooCommerce. |
| `config` | Configuracion Laravel, CORS, DB, logs, queue, Sanctum, booking. |
| `database/migrations` | Tablas e indices. |
| `database/seeders` | Datos demo. |
| `resources` | Assets Blade/Vite basicos de Laravel. |
| `routes` | Rutas API, web y consola. |
| `sdd/explore` | Documento exploratorio; parcialmente desactualizado. |
| `tests` | Tests de ejemplo. |

Tamanio aproximado auditado: 5.475 lineas PHP bajo `app`, `database`, `routes`, `config` y `tests`.

## 6. Modulos funcionales

### Autenticacion

`POST /auth/login` valida email/password con `Auth::attempt`, emite token Sanctum con abilities segun rol y devuelve usuario. `POST /auth/logout` borra el token actual.

### Agenda y reservas

`BookingController` lista, muestra, crea, actualiza y cancela reservas. La creacion calcula duracion efectiva desde `duration_minutes`, servicio o `BOOKING_DEFAULT_DURATION_MINUTES`. Rechaza solapamientos por sede o cliente con `409 conflict`.

Regla visible: las reservas canceladas se identifican por `booking_statuses.is_cancellation = true` y se excluyen del scope `active()`.

Riesgo funcional: la validacion de solapamiento no considera `blocked_slots` y no valida solapamiento por `provider_id`. Tambien bloquea por sede completa, lo que podria impedir reservas simultaneas de distintos profesionales en la misma sede.

### Disponibilidad

`SlotAvailabilityService` genera slots entre `locations.opening_time` y `locations.closing_time`, usando duracion/intervalo del request, del servicio o config. Filtra reservas activas del dia.

Riesgo funcional alto: no filtra `blocked_slots`, por lo que puede ofrecer horarios bloqueados.

### Bloqueos horarios

`BlockedSlotController` permite crear, listar y eliminar bloqueos individuales o por grupo repetido. Soporta scope `all`, `location` o `provider`, aunque la logica real resuelve principalmente por `location_id` o location del provider. Rechaza colisiones con otros bloqueos y reservas existentes.

No existe endpoint para actualizar bloqueos, aunque el frontend actual llama `PATCH /blocked-slots/{id}`.

### Clientes/pacientes

`ClientController` lista, muestra, crea, actualiza, desactiva y expone `/me` por coincidencia de email entre usuario y cliente. Valida RUT chileno en creacion/actualizacion.

### Profesionales, sedes, servicios y packs

Los controladores de `Location`, `Service`, `Provider` y `ServicePack` son principalmente de lectura. No hay CRUD completo de administracion para sedes, servicios, providers ni packs.

### Packs de cliente

`ClientPackController` crea packs para clientes, crea sesiones individuales, lista packs y permite usar una sesion asociandola a una reserva. El estado cambia a `completed` cuando no quedan sesiones.

Riesgo: la consulta de packs usa `client_id = user()->id`, lo que mezcla IDs de usuarios con IDs de clientes.

### Ventas y pagos

`SaleController` solo lista y muestra ventas. Las ventas se crean desde webhooks WooCommerce o manualmente por modelo, pero no hay endpoints `POST/PATCH /sales` ni endpoints de transacciones, aunque el frontend los espera.

### WooCommerce

`WebhookController` procesa `order.completed`, `order.refunded`, `customer.created` y `customer.updated`. Registra payloads en `woocommerce_webhooks_log`.

`WooCommerceService` implementa llamadas salientes a WooCommerce REST API para productos, stock y ordenes, pero no se encontro uso actual desde controladores o jobs.

## 7. Entidades y modelo de datos

| Entidad | Tabla | Campos/relaciones relevantes |
| --- | --- | --- |
| User | `users` | `name`, `email`, password hash, `role`, `provider_id`; Sanctum tokens. |
| PersonalAccessToken | `personal_access_tokens` | Tokens Sanctum hasheados, abilities, expiracion opcional. |
| Location | `locations` | Nombre, direccion, ciudad, timezone, `opening_time`, `closing_time`, active, soft delete. |
| Service | `services` | Nombre, duracion, intervalo, min/max duracion, precio, `wc_product_id`, active, soft delete. |
| Provider | `providers` | Datos personales, `location_id`, `user_id`, active, soft delete; relacion many-to-many con servicios. |
| Client | `clients` | Nombre, email, telefono, RUT unico, genero, `wc_customer_id`, direccion, notas, active, soft delete. |
| CustomAttributeTemplate | `custom_attribute_templates` | Campos personalizados: texto, numero, fecha, select, checkbox. |
| ClientCustomAttribute | `client_custom_attributes` | Valor por cliente/template, unico por par. |
| BookingStatus | `booking_statuses` | Nombre, color, `is_cancellation`. |
| Booking | `bookings` | Cliente, servicio, provider, location, status, inicio/fin, duracion custom, precio, notas, `wc_order_id`, soft delete. |
| BookingStatusHistory | `booking_status_history` | Historial de estado por reserva. |
| Sale | `sales` | Booking, `wc_order_id`, total, paid_amount, metodo, paid_at. |
| ServicePack | `service_packs` | Servicio, nombre, total sesiones, precio, active, soft delete. |
| ClientPack | `client_packs` | Cliente, pack, `wc_order_id`, total/usadas, estado `active/completed/cancelled`, soft delete. |
| PackSession | `pack_sessions` | Sesion individual, booking opcional, estado `pending/scheduled/attended/cancelled`. |
| BlockedSlot | `blocked_slots` | Sede/profesional opcional, inicio/fin, razon, `repeat_group_id`. |
| WoocommerceWebhooksLog | `woocommerce_webhooks_log` | Evento, entidad WC, payload JSON, estado, error. |

Restricciones/indices visibles:

- Unicos: `users.email`, `providers.email`, `clients.email`, `clients.rut`, `personal_access_tokens.token`, `providers.user_id`, `client_custom_attributes(client_id, custom_attribute_template_id)`.
- Indices: `wc_order_id` en `sales` y `client_packs`, `repeat_group_id`, jobs/cache/sessions Laravel.
- SoftDeletes en sedes, servicios, providers, clients, bookings, service packs y client packs.

Transacciones: no se encontraron `DB::transaction`. Operaciones compuestas como crear pack + sesiones, usar pack + incrementar contador, o webhook + venta + historial no estan protegidas transaccionalmente.

Backups: NO DETERMINADO. No hay estrategia visible en repo.

Datos sensibles almacenados:

- PII de clientes/profesionales: nombres, email, telefono, direccion, RUT, notas.
- Hashes de password.
- Tokens Sanctum hasheados.
- Payloads completos de WooCommerce en `woocommerce_webhooks_log`, potencialmente con datos personales y de pago.
- Sesiones Laravel pueden guardar IP/user agent si se usa driver `database`.

Preparar base limpia local:

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan serve --host=127.0.0.1 --port=9999
```

Para QA/produccion no usar `migrate:fresh` ni seeders demo sobre bases compartidas.

## 8. Inventario de endpoints

Base esperada local para frontend: `http://127.0.0.1:9999/api/v1`. En Laravel, las rutas de `routes/api.php` quedan bajo prefijo global `/api`, por lo que `Route::prefix('v1')` produce `/api/v1/...`.

Formato Laravel comun:

- Colecciones paginadas con Resources: usualmente `{ data: [...], links: {...}, meta: {...} }`.
- Recursos unitarios implementados manualmente: `{ data: {...} }`.
- Validacion: `422` con `message` y `errors`.
- Model not found: `404`.
- Scope/rol insuficiente: `403`.
- Token ausente/invalido: `401`.

| Metodo | Ruta | Auth | Proposito | Parametros/body | Respuestas/codigos |
| --- | --- | --- | --- | --- | --- |
| POST | `/api/v1/auth/login` | Publico, throttle `api_public` | Login y emision de token Sanctum. | Body: `email` requerido email, `password` requerido string. | `200 {token,user}`; `422` credenciales/validacion. |
| POST | `/api/v1/auth/logout` | Bearer Sanctum, throttle `api_public` | Borra token actual. | Sin body requerido. | `200 {message}`; `401`. |
| GET | `/api/v1/available_slots` | Publico, throttle `api_public` | Calcular slots disponibles. | Query: `location_id` requerido, `start_date` requerido `Y-m-d`, `service_id`, `provider_id`, `duration_minutes`, `slot_interval`. | `200 {data,meta}`; `422`; `404` location/service/provider. |
| GET | `/api/v1/services` | Publico | Lista servicios paginados. | Query: `active`, `per_page`. | `200` paginado. |
| GET | `/api/v1/services/{id}` | Publico | Detalle servicio con providers. | Path `id`. | `200 {data}`; `404`. |
| GET | `/api/v1/locations` | Publico | Lista sedes paginadas. | Query: `active`, `per_page`. | `200` paginado. |
| GET | `/api/v1/locations/{id}` | Publico | Detalle sede con providers. | Path `id`. | `200 {data}`; `404`. |
| GET | `/api/v1/packs` | Publico | Lista packs activos de servicio. | Sin filtros visibles. | `200 {data:[...]}` no paginado real. |
| GET | `/api/v1/packs/{id}` | Publico | Detalle pack. | Path `id`. | `200 {data}`; `404`. |
| GET | `/api/v1/bookings` | Bearer + `bookings:read` | Lista reservas. Providers filtran por sedes asociadas. | Query: `client_id`, `service_id`, `provider_id`, `location_id`, `status_id`, `date_from`, `date_to`, `wc_order_id`, `per_page`. | `200` paginado; `401/403`. |
| GET | `/api/v1/bookings/{id}` | Bearer + `bookings:read` | Detalle reserva. | Path `id`. | `200 {data}`; `404`; `401/403`. |
| POST | `/api/v1/bookings` | Bearer + `bookings:write` | Crear reserva. | Body requerido: `start_time`, `service_id`, `client_id`, `location_id`, `status_id`; opcional `end_time`, `provider_id`, `price`, `notes`, `duration_minutes`, `wc_order_id`. | `201 {data}`; `409 conflict`; `422`; `401/403`. |
| PATCH | `/api/v1/bookings/{id}` | Bearer + `bookings:write` + role `provider,admin` | Actualizar reserva. | Body opcional: `start_time`, `end_time`, `status_id`, `price`, `notes`, `provider_id`. | `200 {data}`; `409`; `422`; `401/403/404`. |
| PATCH | `/api/v1/bookings/{id}/cancel` | Bearer + `bookings:write` | Cancelar reserva. | Body opcional: `notes`. | `200 {data}`; `422 already_cancelled`; `404`; `401/403`. |
| GET | `/api/v1/clients` | Bearer + `clients:read` | Lista clientes. | Query: `email`, `search`, `active`, `wc_customer_id`, `per_page`. | `200` paginado; `401/403`. |
| GET | `/api/v1/clients/{id}` | Bearer + `clients:read` | Detalle cliente con bookings/status y atributos. | Path `id`. | `200 {data}`; `404`; `401/403`. |
| POST | `/api/v1/clients` | Bearer + `clients:write` | Crear cliente. | Body: `first_name` requerido; `last_name`, `email` unico, `phone`, `rut` unico/RUT valido, `gender`, `wc_customer_id`, `notes`. | `201 {data}`; `422`; `401/403`. |
| PATCH | `/api/v1/clients/{id}` | Bearer + `clients:write` | Actualizar cliente. | Campos de cliente con `sometimes`; RUT/email unicos ignorando id. | `200 {data}`; `422`; `404`; `401/403`. |
| PATCH | `/api/v1/clients/{id}/deactivate` | Bearer + `clients:write` | Marca cliente inactivo. | Path `id`. | `200 {data}`; `422 already_inactive`; `404`. |
| GET | `/api/v1/clients/{id}/attributes` | Bearer + `clients:read` | Atributos personalizados de cliente. | Path `id`. | `200 {data}`. |
| GET | `/api/v1/clients/{id}/packs` | Bearer + `clients:read` | Packs de un cliente. | Path `id`. | `200 {data}`. |
| GET | `/api/v1/providers` | Bearer + `providers:read` | Lista providers. | Query: `location_id`, `service_id`, `active`, `per_page`. | `200` paginado. |
| GET | `/api/v1/providers/{id}` | Bearer + `providers:read` | Detalle provider. | Path `id`. | `200 {data}`; `404`. |
| GET | `/api/v1/sales` | Bearer + `sales:read` | Lista ventas. | Query: `booking_id`, `wc_order_id`, `payment_method`, `date_from`, `date_to`, `per_page`. | `200` paginado. |
| GET | `/api/v1/sales/{id}` | Bearer + `sales:read` | Detalle venta con booking. | Path `id`. | `200 {data}`; `404`. |
| GET | `/api/v1/custom_attributes` | Bearer + `clients:read` | Lista templates de atributos. | Sin filtros. | `200 {data}`. |
| GET | `/api/v1/client-packs` | Bearer + `clients:read` | Lista packs del usuario actual segun `user()->id`. | Sin filtros usados. | `200 {data}`. |
| GET | `/api/v1/client-packs/{id}` | Bearer + `clients:read` | Detalle pack del usuario actual. | Path `id`. | `200 {data,meta}`; `404`. |
| POST | `/api/v1/client-packs` | Bearer + `clients:write` | Crear pack para cliente y sus sesiones. | Body: `client_id`, `service_pack_id`, `wc_order_id`. | `201 {data}`; `422`; `401/403`. |
| PATCH | `/api/v1/client-packs/{id}/use` | Bearer + `bookings:write` | Usa primera sesion pendiente y la asocia a booking. | Body: `booking_id`. | `200 {data,meta}`; `422 pack_not_active/no_sessions_remaining`; `404`. |
| GET | `/api/v1/blocked-slots` | Bearer + `bookings:read` | Lista bloqueos. | Query: `date_from`, `date_to`, `provider_id`, `location_id`. | `200 {data}`. |
| POST | `/api/v1/blocked-slots` | Bearer + `bookings:write` | Crea bloqueo simple o repetido. | Body: `start_time`, `end_time`, `reason`, `scope`, `provider_id`, `location_id`, `repeat`. | `201 {data}`; `409 slot_collision`; `422`; `401/403`. |
| DELETE | `/api/v1/blocked-slots/{id}` | Bearer + `bookings:write` | Elimina bloqueo individual. | Path `id`. | `204`; `404`. |
| DELETE | `/api/v1/blocked-slots/group/{repeatGroupId}` | Bearer + `bookings:write` | Elimina grupo repetido. | Path `repeatGroupId`. | `204`. |
| GET | `/api/v1/me` | Bearer + `clients:read` | Cliente asociado al email del usuario. | Sin parametros. | `200 {data}`; `404 client_not_found`. |
| POST | `/api/v1/webhooks/woocommerce` | HMAC + throttle `woocommerce` | Procesa eventos WooCommerce. | Headers `X-WC-Webhook-Signature`, `X-WC-Webhook-Topic`; body JSON crudo. | `200 {received:true}`; `401 invalid signature`; `500 processing_failed`. |
| GET | `/up` | Publico | Health check Laravel. | Sin parametros. | `200` si app levanta. |
| GET | `/` | Publico | Vista welcome Laravel. | Sin parametros. | `200`. |

Documentacion API:

- Swagger/OpenAPI local: NO DETERMINADO; no se encontro archivo ni dependencia.
- Postman Collection: NO DETERMINADO; no se encontro en repo.
- GraphQL: no se encontro.
- WebSockets: no se encontro.
- Documentacion externa: README referencia Notion, no verificada desde el repo.

## 9. Autenticacion y autorizacion

Mecanismo:

- Login por email/password usando guard Laravel.
- Passwords hasheados por cast `hashed` en `User`.
- Tokens Bearer Sanctum.
- Abilities por rol:
  - `admin`: `*`.
  - `provider`: `bookings:read`, `bookings:write`, `clients:read`.
  - `woocommerce`: `clients:read`, `clients:write`, `bookings:read`, `bookings:write`.
  - `client`: `clients:read`.

Middlewares:

- `scope`: valida abilities del token.
- `role`: valida rol.
- `ownership`: existe, pero no se usa en `routes/api.php`.

Riesgos de autorizacion:

- Providers pueden consultar `GET /bookings/{id}` sin filtro de ownership y actualizar por ID si tienen scope/rol.
- Clientes con token `clients:read` podrian consultar endpoints de clientes por ID porque no hay ownership.
- `/client-packs` mezcla `users.id` con `clients.id`.
- `CheckOwnership` referencia relacion `locations` en `Provider`, pero el modelo actual ya no expone esa relacion; ademas no esta aplicado.

## 10. Integraciones externas

| Integracion | Uso actual | Configuracion/credenciales | Si falla |
| --- | --- | --- | --- |
| WooCommerce webhooks | Confirmar/cancelar reservas, crear ventas, sincronizar clientes. | `WC_WEBHOOK_SECRET` via `config/services.php`. | Webhook invalido devuelve `401`; errores de procesamiento registran log failed y devuelven `500`. |
| WooCommerce REST API | Cliente PHP para productos/stock/ordenes. No se encontro uso actual. | `WC_BASE_URL` en servicio, `WC_CONSUMER_KEY`, `WC_CONSUMER_SECRET`. Nota: config usa `WC_STORE_URL`, servicio usa `WC_BASE_URL`. | Metodos devuelven `[]` o `false`; impacto actual bajo si no se invoca. |
| Mail | Config Laravel; `.env.example` usa `MAIL_MAILER=log`. | Variables MAIL_* o servicios Postmark/Resend/SES. | No se encontraron envios reales. |
| AWS/S3/SQS/SES | Config Laravel disponible. | Variables AWS_*. | No se encontro uso funcional actual. |
| Redis/Memcached | Config Laravel disponible. | Variables REDIS/MEMCACHED. | No se encontro uso explicito; cache/queue por defecto usan database. |
| Slack/Papertrail | Config de logs Laravel disponible. | LOG_SLACK_WEBHOOK_URL/PAPERTRAIL. | No se encontro uso especifico. |

Mock/local:

- WooCommerce puede omitirse para desarrollo de agenda si no se prueban webhooks ni REST WC.
- Mail local puede quedarse en `log`.
- SQLite permite desarrollo local sin servicio DB externo.

## 11. Variables de entorno

`.env.example` actual contiene variables Laravel base, pero no declara las variables especificas de booking ni WooCommerce mencionadas por README/config.

Variables base:

- `APP_NAME`, `APP_ENV`, `APP_KEY`, `APP_DEBUG`, `APP_URL`.
- `DB_CONNECTION`, `DB_DATABASE`, `DB_HOST`, `DB_PORT`, `DB_USERNAME`, `DB_PASSWORD`.
- `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION`.
- `LOG_CHANNEL`, `LOG_STACK`, `LOG_LEVEL`.
- `MAIL_*`, `AWS_*`, `REDIS_*`, `VITE_APP_NAME`.

Variables especificas detectadas por codigo/config:

- `BOOKING_DEFAULT_DURATION_MINUTES`.
- `BOOKING_SLOT_INTERVAL_MINUTES`.
- `BOOKING_MIN_DURATION_MINUTES`.
- `BOOKING_MAX_DURATION_MINUTES`.
- `WC_WEBHOOK_SECRET`.
- `WC_CONSUMER_KEY`.
- `WC_CONSUMER_SECRET`.
- `WC_STORE_URL`.
- `WC_BASE_URL` (usada por `WooCommerceService`; no aparece en `config/services.php`).
- `SANCTUM_STATEFUL_DOMAINS`.
- `SANCTUM_TOKEN_PREFIX`.

NO DETERMINADO: valores reales por ambiente, dominios finales, credenciales WooCommerce, politica de rotacion de secretos.

## 12. Ejecucion local

Comandos ejecutados durante auditoria:

```bash
php -v
composer --version
node --version
npm --version
npm audit --audit-level=low
npm run build
docker --version
```

Resultado:

- `php`: no instalado (`command not found`).
- `composer`: no instalado (`command not found`).
- `docker`: no instalado (`command not found`).
- `node`: disponible `v22.22.1`.
- `npm`: disponible `9.2.0`.
- `vendor/`: no existe.
- `node_modules/`: no existe.
- `npm audit`: fallo porque no hay lockfile npm.
- `npm run build`: fallo porque `vite` no esta instalado.

No se ejecuto `composer install`, `php artisan migrate`, `php artisan serve` ni PHPUnit porque faltan PHP/Composer. No se creo `.env` local porque no se podia completar el flujo Laravel.

Comando esperado de desarrollo segun `composer.json`:

```bash
composer run dev
```

Ese script ejecuta en paralelo:

- `php artisan serve`.
- `php artisan queue:listen --tries=1 --timeout=0`.
- `php artisan pail --timeout=0`.
- `npm run dev`.

Para alinear con el frontend actual, se debe servir en `127.0.0.1:9999` o ajustar `Bookwise/src/environments/environment.ts`.

## 13. Tests y calidad

Tests existentes:

- `tests/Feature/ExampleTest.php`: verifica que `/` devuelve 200.
- `tests/Unit/ExampleTest.php`: assert trivial `true`.
- `phpunit.xml`: usa SQLite in-memory, cache array, queue sync, mail array.

Cobertura funcional real: muy baja. No hay tests de:

- Login y scopes.
- Autorizacion por rol/ownership.
- Reservas y colisiones.
- Bloqueos repetidos.
- Disponibilidad.
- Webhook HMAC y eventos WooCommerce.
- Packs/sesiones.
- RUT.
- Recursos JSON esperados por frontend.

Calidad tecnica:

- Separacion basica controller/model/resource/service existe.
- Validaciones inline generan controladores grandes.
- Hay recursos versionados y recursos no versionados duplicados.
- `spatie/laravel-query-builder` esta instalado pero no se usa en controladores.
- `CheckOwnership` parece obsoleto/inaplicado.
- Algunas relaciones/modelos reflejan decisiones antiguas: pivot `location_provider` fue eliminado, pero codigo/middleware aun habla de varias ubicaciones.
- No se encontro observabilidad estructurada ni trazas.
- No se encontro CI/CD.

## 14. Seguridad

| Severidad | Hallazgo | Evidencia | Riesgo |
| --- | --- | --- | --- |
| Critico | No debe ejecutarse seeder demo en produccion. | `DatabaseSeeder` llama `TestDataSeeder`, que crea usuarios de prueba con credenciales conocidas. | Toma de cuentas si una base productiva se inicializa con seeders demo. |
| Alto | Webhook puede quedar firmado con secreto vacio si `WC_WEBHOOK_SECRET` no se configura. | `WebhookController` usa `config('services.woocommerce.webhook_secret')` sin validar presencia. | Un atacante podria construir firma con secreto vacio si el ambiente queda mal configurado. |
| Alto | Ownership no aplicado en endpoints sensibles. | Rutas no usan middleware `ownership`; show/update/cancel de bookings no filtran por propietario. | Providers/clientes podrian acceder o modificar datos ajenos si tienen token y conocen IDs. |
| Alto | `client-packs` usa `user()->id` como `client_id`. | `ClientPackController::index/show` filtra `client_id` por ID de usuario. | Acceso incorrecto o denegacion; posible exposicion si IDs coinciden. |
| Medio | Tokens Sanctum sin expiracion global. | `config/sanctum.php` tiene `expiration => null`. | Tokens robados duran indefinidamente hasta revocacion manual. |
| Medio | Payload WooCommerce completo se guarda en DB. | `woocommerce_webhooks_log.payload`. | PII/datos comerciales en logs persistentes; requiere retencion, acceso y sanitizacion. |
| Medio | No hay transacciones DB en operaciones compuestas. | No se encontro `DB::transaction`. | Inconsistencias ante fallas parciales o concurrencia. |
| Medio | Autorizacion de clientes demasiado amplia. | Rol `client` tiene `clients:read`; rutas de clientes no filtran ownership. | Clientes podrian leer otros clientes. |
| Bajo | CORS fijo en config. | `config/cors.php` lista localhost y dominios Kinesilk. | Debe revisarse por ambiente; no parece wildcard. |
| Bajo | README y config de WooCommerce no coinciden. | README usa `WC_STORE_URL`; servicio usa `WC_BASE_URL`. | Errores operativos o uso de valores incorrectos. |

Protecciones presentes:

- Password hashing.
- Sanctum Bearer tokens.
- Scopes por token.
- Rate limiting: publico 60/min/IP, autenticado 300/min/usuario/IP, WooCommerce 120/min/IP.
- HMAC para webhooks.
- Validacion de inputs Laravel en endpoints principales.
- RUT chileno validado en clientes.

CSRF: API usa Bearer tokens y no depende de cookies para endpoints versionados; Sanctum mantiene config de CSRF para SPA stateful, pero el frontend actual usa Bearer en `localStorage`.

## 15. Logs y observabilidad

Logs Laravel:

- `LOG_CHANNEL=stack`, `LOG_STACK=single` en `.env.example`.
- Canales disponibles: single, daily, slack, papertrail, stderr, syslog, errorlog, null.
- `laravel/pail` esta en dev dependencies y `composer run dev` lo ejecuta.

Observabilidad funcional:

- Tabla `woocommerce_webhooks_log` audita eventos WooCommerce con estado `received/processed/failed` y error.

No se encontro:

- Request IDs/correlation IDs.
- Metricas.
- Health check especializado de DB/WooCommerce.
- Alertas.
- Sentry/NewRelic/OpenTelemetry.
- Politica de retencion de logs.

## 16. Dependencias y versiones

PHP (`composer.lock`):

- `laravel/framework v13.7.0`.
- `laravel/sanctum v4.3.1`.
- `laravel/tinker v3.0.2`.
- `guzzlehttp/guzzle 7.10.0`.
- `spatie/laravel-query-builder 7.2.1`.
- Dev: `laravel/pail v1.2.6`, `laravel/pint v1.29.1`, `phpunit/phpunit 12.5.23`.

Node (`package.json`):

- `vite ^8.0.0`.
- `laravel-vite-plugin ^3.0.0`.
- `tailwindcss ^4.0.0`.
- `@tailwindcss/vite ^4.0.0`.
- `concurrently ^9.0.1`.

Vulnerabilidades:

- `composer audit`: NO EJECUTADO; no hay Composer instalado.
- `npm audit`: NO EJECUTADO efectivamente; fallo por falta de `package-lock.json`.
- Revision online de CVEs: NO DETERMINADO.

## 17. Estado de preparacion para despliegue

No listo para produccion.

Existe:

- Laravel API desplegable por servidor PHP tradicional.
- `public/.htaccess` para Apache.
- Health `/up`.
- Config por `.env`.
- Migrations.
- Logs Laravel.
- Rate limiting.

No existe en repo:

- Dockerfile.
- Docker Compose.
- Kubernetes.
- systemd unit.
- PM2.
- Nginx config.
- Apache vhost config.
- Pipeline CI/CD.
- Script de deploy.
- Estrategia de rollback.
- Backups.
- Migraciones automatizadas por ambiente.
- Checklist de variables productivas.
- Separacion de seeders demo vs productivos.

Bloqueo operativo actual: falta definir runtime PHP 8.3+, servidor web, DB productiva, estrategia de secretos, dominio, HTTPS, politica de logs/backups y pipeline.

## 18. Propuesta inicial de infraestructura

### Desarrollo

- PHP 8.3+, Composer 2, Node compatible con Vite 8.
- SQLite local.
- `.env` local sin credenciales reales.
- `php artisan serve --host=127.0.0.1 --port=9999`.
- `npm run dev` solo si se necesita compilar assets Laravel; la API no depende funcionalmente de assets para JSON.
- Mail `log`, queue `sync` o `database`.
- Seeds demo permitidos solo en base local.

### QA/Staging

- Servidor Linux con Nginx + PHP-FPM 8.3+ o Apache + PHP-FPM.
- DB MariaDB/MySQL o SQLite solo si el volumen/concurrencia es bajo y aceptado por arquitectura. Recomendacion inicial: MySQL/MariaDB gestionado o con backups.
- `.env` especifico de QA con `APP_ENV=staging`, `APP_DEBUG=false`, `APP_KEY` propio, `WC_WEBHOOK_SECRET` propio, CORS de QA.
- Deploy versionado desde artefacto o rama protegida.
- Ejecutar `composer install --no-dev --optimize-autoloader`.
- `php artisan migrate --force`.
- `php artisan config:cache`, `route:cache`, `view:cache`.
- Health smoke: `GET /up`, login con cuenta QA, `GET /api/v1/locations`.
- Logs a archivo diario o stdout + agregador.

### Produccion

- Nginx/Apache con HTTPS obligatorio.
- PHP-FPM 8.3+.
- DB con backups automaticos, retencion y prueba de restore.
- Secrets fuera de Git.
- `APP_DEBUG=false`.
- `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` definidos segun volumen.
- No ejecutar `DatabaseSeeder` demo.
- Crear usuarios reales por proceso controlado.
- Configurar WooCommerce webhook con secret fuerte y rotacion documentada.
- Restringir CORS a dominios productivos.
- Monitoreo de `/up`, errores 5xx y fallos de webhook.
- Plan de rollback de release y DB.

Datos a solicitar antes de publicar:

- Dominio/subdominio final de API.
- Dominio frontend QA/produccion para CORS.
- Motor DB decidido, host, usuario, password, base, backups.
- Credenciales WooCommerce por ambiente.
- Webhook secret por ambiente.
- Politica de usuarios iniciales/admin.
- Servidor objetivo y acceso.
- Volumen esperado de reservas/webhooks.
- Retencion de logs y payloads WooCommerce.
- Requisitos legales sobre RUT/datos de salud.

## 19. Contrato preliminar API → Frontend

Base URL:

- Desarrollo frontend actual: `http://127.0.0.1:9999/api/v1`.
- Produccion frontend actual: `/api/v1`.

Autenticacion:

- Login: `POST /auth/login` con `{ email, password }`.
- Respuesta: `{ token, user: { id, name, email, role, provider_id } }`.
- Enviar `Authorization: Bearer <token>` en endpoints autenticados.
- Logout local del frontend borra `localStorage`; backend tambien tiene `POST /auth/logout`.

Roles:

- Backend soporta `admin`, `provider`, `client`, `woocommerce`.
- Frontend modela principalmente `admin` y `provider`; debe revisarse si se habilitara `client`.

Endpoints listos para integrar:

- `GET /locations`, `GET /locations/{id}`.
- `GET /services`, `GET /services/{id}`.
- `GET /providers`, `GET /providers/{id}`.
- `GET /packs`, `GET /packs/{id}`.
- `GET /bookings`, `GET /bookings/{id}`, `POST /bookings`, `PATCH /bookings/{id}`, `PATCH /bookings/{id}/cancel`.
- `GET /clients`, `GET /clients/{id}`, `POST /clients`, `PATCH /clients/{id}`, `PATCH /clients/{id}/deactivate`.
- `GET /blocked-slots`, `POST /blocked-slots`, `DELETE /blocked-slots/{id}`, `DELETE /blocked-slots/group/{repeatGroupId}`.
- `GET /sales`, `GET /sales/{id}`.
- `GET /client-packs`, `GET /client-packs/{id}`, `POST /client-packs`, `PATCH /client-packs/{id}/use`.

Brechas frontend/backend verificadas:

- Frontend llama `POST /register`; backend no lo expone.
- Frontend llama `POST /services`; backend no lo expone.
- Frontend llama `PATCH /blocked-slots/{id}`; backend no lo expone.
- Frontend llama `POST /sales`, `PATCH /sales/{id}`, `/sales/{id}/transactions`; backend no los expone.
- Frontend `ApiService.getAvailableSlots` manda query `date`; backend exige `start_date`.
- `AvailabilityService.getLocationAvailability` no manda `start_date`, por lo que backend responderia `422`.
- Modelo frontend `ClientPack.status` espera `active | used | expired`; backend usa `active | completed | cancelled`.
- Frontend documenta reglas de ventas/transacciones que no existen en el backend actual.
- La API devuelve colecciones Laravel Resource paginadas para varios endpoints; algunos metodos frontend tipan `Location[]`, `Provider[]`, `Client[]` directamente. Claudio debe normalizar `response.data` o el backend debe acordar formato.

Formato de errores:

- Laravel validation: `{ message, errors: { field: [messages] } }`.
- Errores de negocio: `{ error, detail, ... }`.
- Conflictos de horario: `{ error: "conflict"|"slot_collision", detail, conflicts_with }`.
- Webhook no autorizado: `{ error: "unauthorized", detail }`.

CORS:

- Permitidos actualmente: localhost, `127.0.0.1:9999`, puerto frontend `4200`, `kinesilk.local`, `kinesilk.cl` y `www.kinesilk.cl`.

Archivos:

- No se encontraron endpoints de upload/download.

Paginacion:

- Laravel pagination usa `data`, `links`, `meta`.
- `packs` y `blocked-slots` devuelven `data` sin paginacion.

Datos mock/seeds:

- `TestDataSeeder` crea sedes, servicios, providers, clientes, reservas semana 2026-05-04 a 2026-05-10, ventas y packs. Usar solo local/QA controlado, nunca produccion.

## 20. Riesgos

- Despliegue con seeders demo o credenciales de prueba.
- Autorizacion insuficiente por ownership.
- Disponibilidad incorrecta por ignorar bloqueos.
- Reservas creadas dentro de horarios bloqueados.
- Incompatibilidades actuales con frontend.
- Falta de PHP/Composer/Docker en entorno auditado impidio validacion runtime.
- README y documentacion exploratoria no siempre coinciden con codigo.
- Sin CI/CD ni tests funcionales, alto riesgo de regresion.
- Payloads WooCommerce con PII guardados sin politica visible.
- No hay plan de backup/restore visible.

## 21. Bloqueadores

- Instalar/proveer PHP 8.3+, Composer y extensiones requeridas.
- Instalar dependencias PHP (`composer install`) y validar `composer.lock`.
- Definir motor DB para QA/prod.
- Definir variables reales por ambiente.
- Resolver brechas frontend/backend minimas.
- Corregir autorizacion/ownership antes de exponer datos reales.
- Decidir y documentar proceso de creacion de usuarios iniciales sin seeders demo.
- Configurar infraestructura HTTPS y CORS final.

## 22. Incognitas

- NO DETERMINADO: proveedor/servidor final de despliegue.
- NO DETERMINADO: dominio final API.
- NO DETERMINADO: si produccion usara SQLite, MySQL o MariaDB.
- NO DETERMINADO: volumen esperado y concurrencia.
- NO DETERMINADO: politica legal sobre datos de salud/RUT.
- NO DETERMINADO: credenciales WooCommerce y tienda final por ambiente.
- NO DETERMINADO: si se requiere portal cliente real o solo admin/provider.
- NO DETERMINADO: si Kinesilk es tenant unico o caso inicial de Bookwise multi-cliente.
- NO DETERMINADO: cobertura real de Notion externo.
- NO DETERMINADO: vulnerabilidades actuales via `composer audit` o bases CVE, porque faltan herramientas/runtime.

## 23. Recomendaciones priorizadas

1. Bloquear seeders demo en produccion y definir proceso seguro de usuario admin inicial.
2. Corregir ownership/autorizacion en bookings, clients y client-packs.
3. Exigir `WC_WEBHOOK_SECRET` no vacio en bootstrap/configuracion.
4. Alinear contrato con frontend: `available_slots`, sales/transacciones, register, services y blocked-slots update.
5. Incorporar `blocked_slots` en disponibilidad y validacion de creacion/actualizacion de reservas.
6. Agregar tests funcionales para auth, scopes, reservas, bloqueos, disponibilidad, webhooks y packs.
7. Separar `.env.example` por variables reales requeridas de Bookwise/WooCommerce.
8. Definir infraestructura QA/prod y pipeline minimo.
9. Revisar estrategia de logs/retencion para payloads WooCommerce.
10. Actualizar README para reflejar Laravel/PHP actuales y estado real de endpoints.

## 24. Proximos pasos

1. Preparar entorno local con PHP 8.3+, Composer y extensiones requeridas.
2. Ejecutar `composer install`, `php artisan test`, `php artisan migrate --seed` sobre SQLite local.
3. Levantar API en `127.0.0.1:9999` y probar smoke endpoints.
4. Validar contrato con Claudio usando llamadas reales desde frontend.
5. Crear checklist de decisiones de despliegue QA.
6. Planificar fixes en issues separados; no mezclar despliegue con cambios funcionales grandes.

## Tabla de decisiones

| ID | Tema | Estado actual | Riesgo | Decision necesaria | Responsable sugerido |
| -- | ---- | ------------- | ------ | ------------------ | -------------------- |
| D-001 | Runtime backend | `composer.json` exige PHP 8.3+ y Laravel 13; README dice PHP 8.2/Laravel 11. | Medio | Version oficial soportada y actualizacion de docs. | Arquitecto backend |
| D-002 | Base de datos | Default SQLite; README menciona MySQL 8.0. | Alto | Motor definitivo QA/prod y politica backup/restore. | Arquitecto + DevOps |
| D-003 | Seeders demo | `DatabaseSeeder` ejecuta datos y usuarios demo. | Critico | Separar seed local de prod y mecanismo admin inicial. | Backend |
| D-004 | Ownership | Middleware existe pero no se aplica; rutas no filtran todos los casos. | Alto | Modelo de permisos por rol y recurso. | Backend + Arquitecto |
| D-005 | Frontend contract | Varias llamadas frontend no existen o no calzan con parametros/respuestas. | Alto | Congelar contrato API v1 y backlog de brechas. | Backend + Claudio |
| D-006 | WooCommerce secrets | Secret y credenciales no estan en `.env.example`; servicio usa `WC_BASE_URL`, config usa `WC_STORE_URL`. | Alto | Nombres de variables y validacion obligatoria por ambiente. | Backend + DevOps |
| D-007 | Disponibilidad | Slots no consideran bloqueos y reservas no validan bloqueos. | Alto | Regla oficial de disponibilidad y colisiones. | Jefe proyecto + Backend |
| D-008 | Multiples providers por sede | Creacion de reserva bloquea por sede completa, no por provider. | Alto | Confirmar regla de negocio de simultaneidad en una sede. | Jefe proyecto |
| D-009 | Ventas/transacciones | Backend solo lee ventas; frontend espera CRUD y transacciones. | Alto | Decidir alcance de pagos en API v1. | Producto + Backend |
| D-010 | Deploy | No hay Docker/CI/CD/scripts. | Alto | Estrategia QA/prod, servidor, pipeline y rollback. | DevOps |
| D-011 | Logs PII | Webhook payload completo queda en DB. | Medio | Retencion, sanitizacion y acceso a logs. | Seguridad + Backend |
| D-012 | Tests | Solo tests ejemplo. | Alto | Cobertura minima obligatoria antes de produccion. | Backend |
| D-013 | CORS/dominios | Config fija dominios locales/Kinesilk. | Medio | Dominios finales por ambiente. | DevOps + Frontend |
| D-014 | Cliente final/tenant | Bookwise vs Kinesilk no esta resuelto en repo. | Medio | Definir naming, tenant y branding operativo. | Jefe proyecto |
