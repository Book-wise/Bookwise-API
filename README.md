# Bookwise API

![Bookwise Logo](assets/images/Bookwise%20logo.png)

![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-8.3-777BB4?logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-8.0-4479A1?logo=mysql&logoColor=white)
![Sanctum](https://img.shields.io/badge/Sanctum-Auth-FF2D20?logo=laravel&logoColor=white)
![Composer](https://img.shields.io/badge/Composer-2-885630?logo=composer&logoColor=white)
![WooCommerce](https://img.shields.io/badge/WooCommerce-Webhooks-7F54B3?logo=woocommerce&logoColor=white)
![Angular](https://img.shields.io/badge/Angular-Consumer-DD0031?logo=angular&logoColor=white)
![WordPress](https://img.shields.io/badge/WordPress-Bridge-21759B?logo=wordpress&logoColor=white)
![REST API](https://img.shields.io/badge/REST-API-009688?logo=fastapi&logoColor=white)

## Setup rápido (recién clonado)

Requiere **PHP 8.3+**, **Composer**, **MySQL** y **MySQL CLI** (viene con el instalador de MySQL).

```powershell
# 1. Crear la base de datos (solo la primera vez)
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS bookwise_api"

# 2. Verificar que se creó correctamente
mysql -u root -p -e "SHOW DATABASES LIKE 'bookwise_api'"

# 3. Instalar todo (dependencias, .env, key, migrations + seeders)
composer setup

# 4. Iniciar servidor
php -S 127.0.0.1:9999 -t public
```

> El script `composer setup` encadena: `composer install` → crea `.env` si no existe → `php artisan key:generate` → `php artisan migrate --force --seed`. Si ya tenés `.env` con `APP_KEY`, no pasa nada, los comandos son idempotentes.

### Configurar correo SMTP (cPanel)

La app envía correos de notificación (confirmación de reserva, recordatorios, pago recibido).
Para que funcione, creá un correo en cPanel de tu dominio y configuralo en el `.env`:

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=mail.tudominio.cl
MAIL_PORT=465
MAIL_USERNAME=notificaciones@tudominio.cl
MAIL_PASSWORD=la-contraseña-del-correo
MAIL_ENCRYPTION=ssl
MAIL_FROM_ADDRESS="notificaciones@tudominio.cl"
MAIL_FROM_NAME="Bookwise"
```

**Pasos en cPanel:**

1. **Crear el correo**: entrar a **Email Accounts** → crear cuenta (ej. `notificaciones@tudominio.cl`)
2. **Configurar el `.env`** con los datos de esa cuenta
3. **Probar** con `php artisan tinker` → `Mail::raw('prueba', fn($m) => $m->to('test@example.com')->subject('test'));`

> Para desarrollo podés dejar `MAIL_MAILER=log` y los correos se escriben en `storage/logs/laravel.log` en lugar de enviarse.

[🇺🇸 English](#english) &nbsp;|&nbsp; [🇨🇱 Español](#español)

---

## English

**REST API for scheduling, session packs, and WooCommerce sync. Each client runs their own independent deployment with dedicated infrastructure and database.**

### What problem does it solve?

Three interconnected problems, each solved across multiple layers:

| # | Problem | Where it's solved | How |
|---|---------|-------------------|-----|
| 1 | **Collision-free scheduling** | **Bookwise App** (Angular) + **Bookwise API** | The app lets admins/providers manage calendars in real time. The API enforces collision detection (`SlotAvailabilityService`), blocks time slots, and prevents double-booking. |
| 2 | **Session packs tied to bookings** | **Bookwise App** + **WooCommerce** (optional) + **WordPress Bridge** (optional) + **Bookwise API** | Clients can buy packs directly in the Bookwise App or through WooCommerce. WooCommerce is an independent sales channel — the bridge plugin captures orders and sends them to the API via webhook. Either way, the API creates the `ClientPack` with individual `PackSessions` and deducts one when the appointment is attended. |
| 3 | **Bidirectional WooCommerce sync** | **WordPress Bridge** + **Bookwise API** | The bridge registers WooCommerce webhooks pointing to the API. The API processes orders, refunds, and customer changes through `WebhookController` and `WooCommerceCustomerService` — all verified via HMAC-SHA256. No polling, no manual sync. |

**Sales and billing**: The Bookwise App can register sales directly without needing to issue invoices or tax documents. It can generate a basic sales receipt for the client's records. Full electronic invoicing (Chilean DTE — boletas/facturas electrónicas) is planned for a future version.

**Bottom line**: The Bookwise App is the central management interface where providers and admins run the daily operation. The API is the engine that orchestrates scheduling, pack deduction, and data sync. WooCommerce serves as an optional independent sales channel connected through the WordPress bridge plugin.

### Why Laravel?

| Decision | Reason |
| --- | --- |
| **Laravel 11 over Lumen** | Form Requests, API Resources, Sanctum, and Eloquent in one package. Lumen would require building those layers from scratch. |
| **Sanctum over Passport** | Simple stateless tokens with scopes. No full OAuth needed — just bearer tokens for three roles. |
| **Eloquent + SoftDeletes** | Auditing is critical in healthcare: clients, bookings, and providers are never physically deleted. |
| **spatie/laravel-query-builder** | URL filters (`?location_id=1&date_from=2026-05-10`) without manual query builders in every controller. |
| **API Resources** | Decouples the model from the response. `BookingResource` can embed `pack_session` without polluting the model. |
| **Form Requests** | Declarative validation with RBAC authorization included. The controller only orchestrates. |

### Module structure

```text
app/
├── Http/
│   ├── Controllers/Api/V1/        # Versioned controllers
│   ├── Middleware/CheckUserRole   # Multi-role RBAC
│   ├── Requests/V1/               # Declarative validation + authorization
│   └── Resources/V1/             # Decoupled serialization
├── Models/                        # Eloquent with SoftDeletes
├── Services/
│   ├── SlotAvailabilityService    — collision detection algorithm
│   ├── WooCommerceService         — order event handling
│   └── WooCommerceCustomerService — client sync
└── Rules/
    └── ChileanRutRule             — Chilean RUT mod-11 validation
```

### API consumers

| Consumer | Stack | Role |
| --- | --- | --- |
| **agendaKinesilk** | Angular | Admin/provider scheduling frontend |
| **wordpress-bridge** | WooCommerce plugin | E-commerce checkout bridge |

### WooCommerce Webhooks

Event-driven integration — WooCommerce pushes, the API never polls.

```http
POST /api/v1/webhooks/woocommerce
X-WC-Webhook-Topic: order.completed
X-WC-Webhook-Signature: <hmac-sha256>
```

Every request is verified with HMAC-SHA256 before any payload processing. Unsupported or invalid signatures return `401` immediately.

| Topic | Action |
| --- | --- |
| `order.completed` | Confirms booking, creates Sale, updates duration |
| `order.refunded` | Cancels booking, deactivates pack |
| `customer.created` | Creates client, syncs RUT from `_billing_rut` |
| `customer.updated` | Updates client data |

### Auth and RBAC

| Role | Access |
| --- | --- |
| `admin` | Full access |
| `provider` | Own bookings only (auto-filtered by `location_id`) |
| `client` | Own bookings and packs |
| `woocommerce` | Bookings + clients only (plugin bridge token) |

### Key endpoints

| Resource | Methods | Auth |
| --- | --- | --- |
| `auth/login`, `auth/logout` | POST | — / Bearer |
| `available_slots` | GET | — |
| `locations`, `services`, `packs` | GET | — |
| `bookings` | GET, POST, PATCH, PATCH `/cancel` | Bearer + `bookings:*` |
| `clients` | GET, POST, PATCH | Bearer + `clients:*` |
| `client-packs` | GET, POST, PATCH `/use` | Bearer + `clients:*` |
| `blocked-slots` | GET, POST, DELETE | Bearer + `bookings:*` |
| `providers` | GET | Bearer + `providers:read` |
| `sales` | GET | Bearer + `sales:read` |
| `webhooks/woocommerce` | POST | HMAC-SHA256 |

Full endpoint documentation on [Notion](https://www.notion.so/Kinesilk-API-Backend-3593f5a371b981109444db7ebda8e2da).

---

## Español

**API REST de agendamiento, packs de sesiones y sincronización con WooCommerce. Cada cliente tiene su propio deploy independiente con infraestructura y base de datos dedicadas.**

### ¿Qué problema resuelve?

Tres problemas interconectados, cada uno resuelto en múltiples capas:

| # | Problema | Dónde se resuelve | Cómo |
|---|----------|-------------------|------|
| 1 | **Agenda sin doble-booking** | **Bookwise App** (Angular) + **Bookwise API** | La app permite a admins/profesionales gestionar calendarios en tiempo real. La API ejecuta la detección de colisiones (`SlotAvailabilityService`), bloquea horarios y previene reservas duplicadas. |
| 2 | **Packs de sesiones vinculados a reservas** | **Bookwise App** + **WooCommerce** (opcional) + **WordPress Bridge** (opcional) + **Bookwise API** | El cliente puede comprar packs directamente en la Bookwise App o a través de WooCommerce. WooCommerce es un canal de venta independiente — el plugin bridge captura las órdenes y las envía a la API via webhook. En ambos casos, la API crea el `ClientPack` con `PackSessions` individuales y descuenta una al atenderse la cita. |
| 3 | **Sincronización bidireccional con WooCommerce** | **WordPress Bridge** + **Bookwise API** | El bridge registra webhooks de WooCommerce apuntando a la API. La API procesa órdenes, reembolsos y cambios de cliente a través de `WebhookController` y `WooCommerceCustomerService` — todo verificado via HMAC-SHA256. Sin polling, sin sync manual. |

**Ventas y facturación**: La Bookwise App puede registrar ventas directamente sin necesidad de emitir boletas o facturas. Puede generar un recibo de venta básico para el registro del cliente. La facturación electrónica (DTE — boletas y facturas electrónicas chilenas) está planificada para una versión futura.

**En resumen**: La Bookwise App es la interfaz central donde profesionales y admins gestionan el día a día. La API es el motor que orquesta la agenda, el descuento de sesiones y la sincronización. WooCommerce funciona como un canal de venta opcional conectado a través del plugin bridge.

### Por qué Laravel

| Decisión | Razón |
| --- | --- |
| **Laravel 11 sobre Lumen** | Form Requests, API Resources, Sanctum y Eloquent en un solo paquete. Lumen requeriría construir esas capas desde cero. |
| **Sanctum sobre Passport** | Tokens stateless simples con scopes. No necesitamos OAuth full — solo bearer tokens para tres roles. |
| **Eloquent + SoftDeletes** | La auditoría es crítica en salud: clientes, reservas y profesionales nunca se borran físicamente. |
| **spatie/laravel-query-builder** | Filtros URL (`?location_id=1&date_from=2026-05-10`) sin escribir query builders manuales en cada controller. |
| **API Resources** | Desacoplan el modelo de la respuesta. `BookingResource` puede incluir `pack_session` embebido sin contaminar el modelo. |
| **Form Requests** | Validación declarativa con autorización RBAC incluida. El controller queda limpio — solo orquesta. |

### Estructura de módulos

```text
app/
├── Http/
│   ├── Controllers/Api/V1/        # Controladores versionados
│   │   ├── AuthController         — login / logout
│   │   ├── BookingController      — CRUD + cancel
│   │   ├── AvailableSlotsController — algoritmo de colisión
│   │   ├── BlockedSlotController  — bloques con repetición
│   │   ├── ClientController       — CRUD + deactivate
│   │   ├── ClientPackController   — packs + /use
│   │   ├── ProviderController     — profesionales por sede
│   │   ├── SaleController         — historial de pagos
│   │   ├── ServicePackController  — packs de servicio
│   │   └── WebhookController      — eventos WooCommerce
│   ├── Middleware/
│   │   └── CheckUserRole          — RBAC multi-rol
│   ├── Requests/V1/               # Validación + autorización declarativa
│   └── Resources/V1/             # Serialización desacoplada
├── Models/                        # Eloquent con SoftDeletes
├── Services/
│   ├── SlotAvailabilityService    — algoritmo de slots libres
│   ├── WooCommerceService         — eventos de orden
│   └── WooCommerceCustomerService — sync de clientes
└── Rules/
    └── ChileanRutRule             — validación módulo 11
```

**Principio de diseño**: cada capa tiene una sola responsabilidad. El controller orquesta, el Service ejecuta lógica de negocio, el Resource serializa. Nunca hay lógica de negocio en el Resource ni queries crudas en el controller.

### Consumidores

#### 1. agendaKinesilk (Angular)

El frontend principal de administración. Consume la API con un token de rol `admin` o `provider`.

- Gestiona reservas, clientes, profesionales y bloques horarios
- Visualiza disponibilidad en tiempo real via `GET /available_slots`
- URL local: `http://localhost:4200`

**Flujo típico:**

```http
POST /auth/login               → Bearer token
GET  /available_slots?location_id=1&start_date=2026-05-10
POST /bookings                 → { start_time, provider_id, client_id, ... }
```

#### 2. wordpress-bridge (WooCommerce Plugin)

Plugin PHP dentro del sitio WordPress de Kinesilk. Actúa como puente entre el checkout de WooCommerce y esta API.

- Registra webhooks en WooCommerce apuntando a `POST /webhooks/woocommerce`
- Cuando un cliente compra un pack, WooCommerce dispara `order.completed`
- La API crea el `ClientPack`, genera las `PackSessions` y confirma la reserva
- URL local: `http://kinesilk.local/` (Local v10)

**Token dedicado**: el plugin usa un Sanctum token con rol `woocommerce` y scopes `bookings:rw + clients:rw`. No tiene acceso a sales, providers ni bloques.

### Webhooks WooCommerce

La integración con WooCommerce es **event-driven, no polling**. WooCommerce llama a la API; la API nunca llama a WooCommerce.

```http
POST /api/v1/webhooks/woocommerce
X-WC-Webhook-Topic: order.completed
X-WC-Webhook-Signature: <hmac-sha256>
```

#### Seguridad — HMAC-SHA256

Cada webhook lleva una firma calculada con el secret compartido (`WC_WEBHOOK_SECRET` en `.env`). El `WebhookController` verifica la firma **antes** de procesar el payload. Un request sin firma válida recibe `401` inmediatamente.

```php
$signature = hash_hmac('sha256', $rawBody, config('services.woocommerce.webhook_secret'));
if (!hash_equals($signature, $receivedSignature)) {
    return response()->json(['error' => 'Invalid signature'], 401);
}
```

#### Eventos soportados

| Topic | Acción en la API |
| --- | --- |
| `order.completed` | Confirma la reserva asociada, crea `Sale`, actualiza `custom_duration_minutes` |
| `order.refunded` | Cancela la reserva y marca el pack como inactivo |
| `customer.created` | Crea el cliente en `clients` con `wc_customer_id` y sincroniza RUT desde `meta_data._billing_rut` |
| `customer.updated` | Actualiza datos del cliente (email, teléfono, RUT) |

#### Trazabilidad completa

Cada webhook — exitoso o fallido — se registra en `webhook_logs`:

```json
{
  "topic": "order.completed",
  "wc_order_id": 456,
  "status": "processed",
  "payload": {},
  "error": null,
  "processed_at": "2026-05-10T09:00:00Z"
}
```

`status` transiciona: `received → processed | failed`. Permite auditar qué órdenes de WooCommerce dispararon qué cambios, sin depender de los logs de WooCommerce.

### Autenticación y RBAC

**Sanctum** emite Bearer tokens con scopes. El middleware `CheckUserRole` evalúa rol y scope en cada ruta.

| Rol | Puede |
| --- | --- |
| `admin` | Todo |
| `provider` | Ver/actualizar sus propias reservas (filtro automático por `location_id`) |
| `client` | Ver sus reservas y usar sus packs |
| `woocommerce` | Solo endpoints de booking y cliente (token del plugin bridge) |

Los scopes granulares (`bookings:read`, `bookings:write`, `clients:read`, etc.) permiten emitir tokens de solo lectura para integraciones externas sin exponer escritura.

### Variables de entorno clave

```dotenv
BOOKING_DEFAULT_DURATION_MINUTES=30
BOOKING_SLOT_INTERVAL_MINUTES=30
BOOKING_MIN_DURATION_MINUTES=15
BOOKING_MAX_DURATION_MINUTES=480

WC_CONSUMER_KEY=ck_...
WC_CONSUMER_SECRET=cs_...
WC_WEBHOOK_SECRET=...
WC_STORE_URL=https://kinesilk.cl

CORS_ALLOWED_ORIGINS=http://localhost:4200,http://kinesilk.local
```

### Endpoints resumidos

| Recurso | Métodos | Auth |
| --- | --- | --- |
| `auth/login`, `auth/logout` | POST | — / Bearer |
| `me` | GET | Bearer |
| `available_slots` | GET | — |
| `locations`, `services`, `packs` | GET | — |
| `providers` | GET | Bearer + `providers:read` |
| `clients` | GET, POST, PATCH | Bearer + `clients:*` |
| `bookings` | GET, POST, PATCH, PATCH `/cancel` | Bearer + `bookings:*` |
| `blocked-slots` | GET, POST, DELETE | Bearer + `bookings:*` |
| `sales` | GET | Bearer + `sales:read` |
| `client-packs` | GET, POST, PATCH `/use` | Bearer + `clients:*` |
| `custom_attributes` | GET | Bearer + `clients:read` |
| `webhooks/woocommerce` | POST | HMAC-SHA256 |

Documentación completa de cada endpoint en [Notion](https://www.notion.so/Kinesilk-API-Backend-3593f5a371b981109444db7ebda8e2da).

### Despliegue

Consulta la **[guía completa de deploy → DEPLOY.md](DEPLOY.md)** con instrucciones detalladas para:

| Proveedor | Tipo | Incluye |
|-----------|------|---------|
| **HostGator** | cPanel (shared) | Subida de archivos, `.env`, Composer, migraciones, CRON recordatorios ⚠️ |
| **DigitalOcean** | VPS Ubuntu + Nginx | Stack LEMP, MySQL, SSL Let's Encrypt, SystemD queue worker |
| **Laravel Cloud** | Serverless | Alternativa zero-maintenance |

> ⚠️ **IMPORTANTE**: Los recordatorios de reserva por email (24h y 30min antes) requieren un CRON en el servidor. Sin él, **no se enviarán**. Ver [DEPLOY.md → Configurar CRON](DEPLOY.md#7-configurar-el-cron-%EF%B8%8F-obligatorio-para-recordatorios).

---

## Documentación relacionada

- [📘 Documentación completa de endpoints (Notion)](https://www.notion.so/Kinesilk-API-Backend-3593f5a371b981109444db7ebda8e2da)
- [🚀 Guía de deploy](DEPLOY.md) — HostGator, DigitalOcean, configuración SMTP, CRON, SSL
- [📧 Sistema de notificaciones](DEPLOY.md#7-configurar-el-cron-%EF%B8%8F-obligatorio-para-recordatorios) — 3 tipos de correo: cita inmediata, recordatorio 24h, recordatorio 30min, pago

---

### Roadmap

- [ ] **Fase 8** — Tests unitarios (SlotAvailabilityService) e integración (BookingController, WebhookController)
- [ ] **~~Fase 9 — Deploy~~** ✅ Documentado en [DEPLOY.md](DEPLOY.md)
- [ ] **Fase 10** — Verificación HTTPS + CORS en producción (`kinesilk.cl`)

---

> [!info] Navegación Obsidian
> - [[index|Índice de documentación]]
> - [[DEPLOY|Deploy]]
> - [[notifications-contract|Contrato de notificaciones]]
