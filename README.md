# Kinesilk API

![Bookwise Logo](assets/images/Bookwise%20logo.png)

![Laravel](https://img.shields.io/badge/Laravel-11-FF2D20?logo=laravel&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-8.2-777BB4?logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-8.0-4479A1?logo=mysql&logoColor=white)
![Sanctum](https://img.shields.io/badge/Sanctum-Auth-FF2D20?logo=laravel&logoColor=white)
![Composer](https://img.shields.io/badge/Composer-2-885630?logo=composer&logoColor=white)
![WooCommerce](https://img.shields.io/badge/WooCommerce-Webhooks-7F54B3?logo=woocommerce&logoColor=white)
![Angular](https://img.shields.io/badge/Angular-Consumer-DD0031?logo=angular&logoColor=white)
![WordPress](https://img.shields.io/badge/WordPress-Bridge-21759B?logo=wordpress&logoColor=white)
![REST API](https://img.shields.io/badge/REST-API-009688?logo=fastapi&logoColor=white)

[🇺🇸 English](#english) &nbsp;|&nbsp; [🇨🇱 Español](#español)

---

## English

**REST API for scheduling, session packs, and WooCommerce sync for Kinesilk — a Chilean kinesiology clinic network.**

### What problem does it solve?

Kinesilk runs multiple kinesiology clinics with independent providers per location, prepaid session packs, and sales through WooCommerce. Three problems had to be solved in one system:

1. **Collision-free scheduling** — multiple providers, multiple locations, blockable time slots with weekly repeat.
2. **Session packs tied to bookings** — a client buys 5 sessions on WooCommerce; the API automatically deducts each session when the appointment is attended.
3. **Bidirectional WooCommerce sync** — the e-commerce confirms bookings, records payments, and syncs clients without manual intervention.

This API is the core that connects those three worlds.

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

**API REST de agendamiento, packs de sesiones y sincronización con WooCommerce para Kinesilk — red de centros kinesiológicos en Chile.**

### ¿Qué problema resuelve?

Kinesilk opera múltiples centros kinesiológicos con profesionales independientes por sede, packs de sesiones prepagadas, y ventas a través de WooCommerce. El desafío era triple:

1. **Coordinación de agenda sin doble-booking** — múltiples profesionales, múltiples sedes, turnos bloqueables con repetición semanal.
2. **Packs de sesiones vinculados a reservas** — un cliente compra 5 sesiones en WooCommerce y la API descuenta automáticamente cada sesión al atenderse.
3. **Sincronización bidireccional con WooCommerce** — el e-commerce confirma reservas, registra pagos y sincroniza clientes sin intervención manual.

Esta API es el núcleo que conecta esos tres mundos.

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

### Roadmap

- [ ] **Fase 8** — Tests unitarios (SlotAvailabilityService) e integración (BookingController, WebhookController)
- [ ] **Fase 9** — Deploy HostGator: `.htaccess`, variables en producción, cron jobs
- [ ] **Fase 10** — Verificación HTTPS + CORS en producción (`kinesilk.cl`)
