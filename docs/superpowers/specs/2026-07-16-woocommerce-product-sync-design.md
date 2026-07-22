# WooCommerce Sync — Design Doc

**Date:** 2026-07-16
**Status:** Draft
**Author:** Gentle AI — Bookwise Orchestrator

---

## 1. Problem

Bookwise API gestiona servicios (`Service`) y paquetes de sesiones (`ServicePack`) como su catálogo interno, mientras que WooCommerce funciona como tienda online donde los clientes compran turnos y paquetes.

Actualmente no hay un vínculo automático entre el catálogo de Bookwise y los productos de WooCommerce, lo que genera duplicación de trabajo, inconsistencias de precios y errores operativos.

---

## 2. Strategic Decisions

### 2.1 Source of Truth for Products: WooCommerce

Para este primer alcance, **WooCommerce es la fuente de verdad para productos**. Las razones:

- WC tiene manejo nativo de imágenes, descripciones, galerías, categorías, atributos y variaciones
- Bookwise no tiene UI de administración de productos ni manejo de imágenes
- El cliente ya opera WC como su tienda — es natural que los productos se creen ahí
- Bookwise **consume** los productos de WC y mantiene un mirror local (`Service`, `ServicePack`)

**A futuro:** Cuando Bookwise tenga capacidades de gestión de productos (imágenes, descripciones), puede pasar a ser la fuente de verdad.

### 2.2 Sync Direction Summary

| Data | Source of Truth | Direction | Mechanism |
|---|---|---|---|
| **Products** (Service, ServicePack) | **WooCommerce** | **WC → Bookwise** | Webhooks `product.*` + bulk sync command |
| **Orders** (Booking, Sale) | WooCommerce | **WC → Bookwise** | Webhooks `order.*` (existentes) |
| **Customers** (Client) | WooCommerce | **WC → Bookwise** | Webhooks `customer.*` (existentes, con validación) |
| **Stock** | N/A | ❌ Eliminado | No aplica a servicios |

### 2.3 What We Are NOT Doing (v1)

- ❌ Product sync Bookwise → WC (se hará en futura iteración cuando Bookwise tenga UI de productos)
- ❌ Stock management / decrement (no tiene sentido para servicios)
- ❌ Bidirectional sync (WC es la única fuente de verdad para productos)

---

## 3. Affected Entities

### 3.1 WC Product → Service

| WooCommerce Product | Bookwise `Service` | Notes |
|---|---|---|
| `name` | `name` | |
| `regular_price` | `price` | |
| `meta_data[_kinesilk_entity_type]=service` | Identifies as a Service | Required to distinguish from packs |
| `meta_data[_kinesilk_entity_id]` | `id` (local) | Already exists — used to match |
| `meta_data[_kinesilk_duration_minutes]` | `duration_minutes` | |
| `meta_data[_kinesilk_slot_interval]` | `slot_interval_minutes` | |
| `meta_data[_kinesilk_min_duration]` | `min_duration_minutes` | |
| `meta_data[_kinesilk_max_duration]` | `max_duration_minutes` | |
| `status` / `catalog_visibility` | `active` | `publish` + `visible` → `active=true` |
| `id` (WC) | `wc_product_id` | Already exists on `services` |

### 3.2 WC Product → ServicePack

| WooCommerce Product | Bookwise `ServicePack` | Notes |
|---|---|---|
| `name` | `name` | |
| `regular_price` | `price` | |
| `meta_data[_kinesilk_entity_type]=service_pack` | Identifies as a pack | Required |
| `meta_data[_kinesilk_entity_id]` | `id` (local) | Match key |
| `meta_data[_kinesilk_pack_total_sessions]` | `total_sessions` | |
| `meta_data[_kinesilk_pack_service_id]` | `service_id` | FK to local service |
| `status` / `catalog_visibility` | `active` | |
| `id` (WC) | `wc_product_id` | **New column needed on `service_packs`** |

ServicePacks se crean como **productos simples** en WC con el precio total del pack.

---

## 4. Architecture

### 4.1 Product Sync — Webhook-Driven

```
Product created/updated/deleted in WooCommerce
  │
  ▼
WC sends webhook → POST /v1/webhooks/woocommerce
  │
  ▼
WebhookController.handle() — validates HMAC signature
  │
  ▼
Job dispatched: SyncProductFromWooCommerce
  │
  ▼
Reads WC product metadata (_kinesilk_entity_type):
  ├─ "service"      → upsert Service
  └─ "service_pack" → upsert ServicePack
  │
  ▼
Sync result logged in ProductSyncLog
  │
  ▼
On failure → retry (3 attempts), log error
```

### 4.2 Components

| Component | Responsibility |
|---|---|
| `App\Services\WooCommerceProductService` | New. Reads products from WC REST API, maps fields to Bookwise models |
| `App\Jobs\SyncProductFromWooCommerce` | New. One per WC product webhook. Dispatched on `product.*` events |
| `App\Models\ProductSyncLog` | New. Logs all sync operations |
| `WebhookController` | Existing. Add routing for `product.*` events alongside existing `customer.*` and `order.*` |
| Artisan commands | `woocommerce:sync-products` — initial bulk import from WC |
| | `woocommerce:sync-status` — health check of last sync per product |

### 4.3 Product Webhook Routing

In `ProcessWooCommerceWebhook` (or a new `SyncProductFromWooCommerce` job), route by event:

```php
match (true) {
    str_contains($event, 'product.')  => $this->handleProductEvent($data),
    str_contains($event, 'customer.') => $this->handleCustomerEvent($data),
    str_contains($event, 'order.')    => $this->handleOrderEvent($data),
};
```

### 4.4 Customer Validation (improvement over current)

The existing `WooCommerceCustomerService::syncCustomer()` upserts by `wc_customer_id`. We add a lookup fallback:

```php
// 1. Try by wc_customer_id (existing upsert)
// 2. If not found, try by email
// 3. If found by email, link wc_customer_id
// 4. If not found at all, create
```

This prevents duplicate `Client` records when a customer buys before the `customer.created` webhook arrives.

---

## 5. Product Sync Flow

### 5.1 WC Product Created

```
1. Admin creates product in WooCommerce (with _kinesilk_* meta_data)
2. WC sends product.created webhook
3. Bookwise validates HMAC, dispatches SyncProductFromWooCommerce
4. Job reads product from WC REST API (to get full data)
5. Checks _kinesilk_entity_type:
   - "service" → create/update Service record, store wc_product_id
   - "service_pack" → create/update ServicePack record, store wc_product_id
6. Logs result in ProductSyncLog
```

### 5.2 WC Product Updated

```
1. Admin edits product in WooCommerce
2. WC sends product.updated webhook
3. Same flow as create — upserts the corresponding Bookwise entity
```

### 5.3 WC Product Deleted

```
1. Admin deletes product in WooCommerce
2. WC sends product.deleted webhook
3. Bookwise sets Service/ServicePack.active = false
4. Logs the deactivation
```

**Decision:** On WC product deletion, we do NOT delete the Bookwise record (soft-delete or hard-delete). We deactivate it to preserve booking history integrity. An admin must manually clean up from Bookwise.

---

## 6. New Database Objects

### 6.1 `service_packs.wc_product_id`

New nullable column to store the WooCommerce product ID:

```sql
ALTER TABLE service_packs ADD COLUMN wc_product_id BIGINT UNSIGNED NULL AFTER total_sessions;
```

### 6.2 `product_sync_log` table

```sql
CREATE TABLE product_sync_log (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type     ENUM('service', 'service_pack') NOT NULL,
    entity_id       BIGINT UNSIGNED NULL,
    wc_product_id   BIGINT UNSIGNED NULL,
    action          ENUM('created', 'updated', 'deleted', 'sync_failed') NOT NULL,
    request         JSON NULL,
    response        JSON NULL,
    status          ENUM('success', 'failed') NOT NULL,
    error_message   TEXT NULL,
    created_at      TIMESTAMP NULL,
    updated_at      TIMESTAMP NULL
) ENGINE=InnoDB;
```

---

## 7. WooCommerce Product Setup Guide

Para que un producto de WC sea reconocido por Bookwise, debe tener los siguientes meta_data (custom fields):

### 7.1 Service Product

| Meta Key | Value | Example |
|---|---|---|
| `_kinesilk_entity_type` | `service` | `service` |
| `_kinesilk_duration_minutes` | Duración en minutos | `60` |
| `_kinesilk_slot_interval` | Intervalo entre turnos | `30` |
| `_kinesilk_min_duration` | Duración mínima | `30` |
| `_kinesilk_max_duration` | Duración máxima | `120` |

### 7.2 ServicePack Product

| Meta Key | Value | Example |
|---|---|---|
| `_kinesilk_entity_type` | `service_pack` | `service_pack` |
| `_kinesilk_pack_total_sessions` | Cantidad de sesiones | `5` |
| `_kinesilk_pack_service_id` | ID del servicio asociado en Bookwise | `42` |

> **Note:** `_kinesilk_pack_service_id` debe corresponder a un `Service` que ya exista en Bookwise (sincronizado previamente).

---

## 8. Initial Bulk Sync

Un comando Artisan para la sincronización inicial:

```bash
php artisan woocommerce:sync-products
```

Flujo:

1. Obtiene todos los productos de WC (paginados, 100 por request)
2. Filtra los que tienen `_kinesilk_entity_type` en meta_data
3. Para cada uno: upserts el Service/ServicePack correspondiente
4. Muestra progress bar con resultados
5. Genera reporte: cuántos creados, actualizados, ignorados, errores

Flags:
- `--dry-run` — muestra lo que haría sin ejecutar
- `--since=YYYY-MM-DD` — solo productos modificados desde esa fecha
- `--product=123` — sincroniza un producto específico

---

## 9. Error Handling

| Scenario | Handling |
|---|---|
| WC API timeout | Retry job 3x with backoff (5s, 30s, 120s) |
| Product lacks `_kinesilk_entity_type` | Ignore — not a Bookwise-managed product |
| `_kinesilk_pack_service_id` refers to non-existent Service | Log warning, skip pack sync until service is synced |
| WC webhook arrives but product not found on WC (e.g., deleted between webhook and read) | Log as deleted, deactivate locally |
| Network failure | Retry. After 3 failures → mark ProductSyncLog as `failed` |

---

## 10. Edge Cases

- **Product created in WC without `_kinesilk_*` meta**: Ignored by Bookwise. No sync occurs.
- **ServicePack references a Service that hasn't synced yet**: The pack sync is skipped and requeued. The bulk sync command handles ordering (services first, then packs).
- **Customer buys before `customer.created` webhook fires**: The `order.completed` handler already syncs billing data. The customer upsert by email fallback (section 4.4) prevents duplicates.
- **WC product deleted accidentally**: Bookwise sets `active=false`. An admin can reactivate from WC and the `product.updated` webhook will re-activate.
- **WC sends duplicate webhooks**: `ShouldBeUnique` on the job prevents duplicate processing within a 60-second window.

---

## 11. Non-Goals (v1)

- ❌ Product sync Bookwise → WC
- ❌ Stock management
- ❌ Image sync
- ❌ WC category/tag sync
- ❌ Bidirectional product editing
- ❌ Bookwise admin UI for products

---

---

## 12. Deployment Considerations — Webhook URL

### 12.1 Current State (Tested)

Toda la integración de webhooks con WooCommerce se ha probado exclusivamente a través de un **túnel HTTP** con `npx tunnelmole 9999`. Esto significa:

- WooCommerce envía los webhooks a una URL pública temporal del túnel
- El túnel reenvía a `localhost:9999` donde corre Bookwise
- HMAC signature validation funciona correctamente a través del túnel
- Funciona para desarrollo local y pruebas

### 12.2 How Tunnelmole Works

Al ejecutar `npx tunnelmole 9999` (o `npx tmole 9999`), tunnelmole:

1. Establece un **WebSocket persistente y seguro** entre tu máquina local y los servidores de tunnelmole.com
2. Escucha requests HTTP entrantes en una URL pública generada aleatoriamente
3. Reenvía cada request a través del WebSocket hacia tu `localhost:9999`

**Output esperado en terminal:**

```
Tunnelmole v2.x.x
Starting tunnel on port 9999
Checking for updates...
Connected!

http://abc123.tunnelmole.net is forwarding to localhost:9999
https://abc123.tunnelmole.net is forwarding to localhost:9999
```

El dominio generado (`abc123.tunnelmole.net`) es la **URL pública temporal** que WooCommerce usará como "Delivery URL" para enviar los webhooks. Cada vez que ejecutás tunnelmole, genera un subdominio distinto.

> **Importante:** Es una URL de desarrollo. En cada reinicio del túnel, la URL cambia.

### 12.3 WooCommerce Webhook Configuration (Dev)

Con el túnel corriendo, configurar los webhooks en WooCommerce:

**1.** En el admin de WooCommerce, ir a:
```
WooCommerce → Settings → Advanced → Webhooks
```

**2.** Click **"Add webhook"**.

**3.** Completar los campos:

| Campo | Valor |
|---|---|
| **Name** | `Bookwise - Product created` (o el nombre descriptivo que prefieras) |
| **Status** | `Active` |
| **Topic** | Elegir el evento según corresponda: `Product created`, `Product updated`, `Order created`, etc. |
| **Delivery URL** | `https://abc123.tunnelmole.net/api/v1/webhooks/woocommerce` (la URL que devolvió tunnelmole en el paso anterior) |
| **Secret** | El valor de `WC_WEBHOOK_SECRET` definido en tu `.env` |
| **API Version** | `v3` (del REST API de WooCommerce, legacy webhooks) |

**4.** Click **"Save webhook"** y repetir por cada tipo de evento necesario.

Los webhooks recomendados para la integración completa son:

| Name | Topic | Delivery URL |
|---|---|---|
| Bookwise - Product created | `Product created` | `https://abc123.tunnelmole.net/api/v1/webhooks/woocommerce` |
| Bookwise - Product updated | `Product updated` | `https://abc123.tunnelmole.net/api/v1/webhooks/woocommerce` |
| Bookwise - Product deleted | `Product deleted` | `https://abc123.tunnelmole.net/api/v1/webhooks/woocommerce` |
| Bookwise - Order created | `Order created` | `https://abc123.tunnelmole.net/api/v1/webhooks/woocommerce` |
| Bookwise - Order updated | `Order updated` | `https://abc123.tunnelmole.net/api/v1/webhooks/woocommerce` |
| Bookwise - Customer created | `Customer created` | `https://abc123.tunnelmole.net/api/v1/webhooks/woocommerce` |
| Bookwise - Customer updated | `Customer updated` | `https://abc123.tunnelmole.net/api/v1/webhooks/woocommerce` |

> **Note:** Todos apuntan a la misma URL. Bookwise distingue el tipo de evento por el header `X-WC-Webhook-Topic` que WooCommerce envía automáticamente.

**5.** Para verificar que funciona, desde WooCommerce se puede mandar un **"Send test"** (en algunos temas/plugins) o simplemente crear un producto de prueba y revisar los logs de Bookwise en `woocommerce_webhooks_log`.

### 12.4 Production Options

| Approach | Status | Notas |
|---|---|---|
| **Túnel (`npx tunnelmole 9999`)** | ✅ Probado | Viable para dev, NO para producción (URL temporal, sin HTTPS dedicado) |
| **Subdominio dedicado** (`webhooks.bookwise.tld`) | ⚠️ No probado | Debería funcionar — WC necesita una URL pública y accesible. El subdominio apunta al servidor donde corre Bookwise. Es la opción recomendada para producción |
| **Ruta en el dominio principal** (`bookwise.tld/v1/webhooks/woocommerce`) | ⚠️ No probado | También debería funcionar. Depende de cómo esté ruteado el dominio principal |
| **IP pública directa** | ❌ No recomendado | WC podría aceptarlo, pero es mala práctica (cambio de IP, certificados SSL) |

### 12.5 Recommendation

Para producción, usar **un subdominio** del tipo `webhooks.bookwise.tld` que apunte al servidor de Bookwise. WooCommerce webhooks requieren:

1. **URL pública accesible desde internet** (WC la llama desde sus servidores)
2. **HTTPS** (WooCommerce no envía webhooks a URLs HTTP en producción)
3. **Endpoint único** `POST /api/v1/webhooks/woocommerce` (ya existe)
4. **Sin autenticación adicional** — la HMAC signature en el header `X-WC-Webhook-Signature` es la única validación necesaria (ya implementada)

### 12.6 WooCommerce Webhook Configuration (Production)

En el admin de WooCommerce → **Settings → Advanced → Webhooks**, crear un webhook por tipo de evento:

| Topic | Delivery URL | Status |
|---|---|---|
| `Product created` | `https://webhooks.bookwise.tld/api/v1/webhooks/woocommerce` | Active |
| `Product updated` | `https://webhooks.bookwise.tld/api/v1/webhooks/woocommerce` | Active |
| `Product deleted` | `https://webhooks.bookwise.tld/api/v1/webhooks/woocommerce` | Active |
| `Order created` | `https://webhooks.bookwise.tld/api/v1/webhooks/woocommerce` | Active (exists) |
| `Order updated` | `https://webhooks.bookwise.tld/api/v1/webhooks/woocommerce` | Active (exists) |
| `Customer created` | `https://webhooks.bookwise.tld/api/v1/webhooks/woocommerce` | Active (exists) |
| `Customer updated` | `https://webhooks.bookwise.tld/api/v1/webhooks/woocommerce` | Active (exists) |

> **Note:** Todos los webhooks apuntan al mismo endpoint. Bookwise discrimina por el header `X-WC-Webhook-Topic` para rutear al handler correcto.

---

## 13. Future Iterations

- Make Bookwise the source of truth for products (requires image handling, product management UI)
- Image sync when Bookwise manages them
- WC category/tag → Bookwise mapping
- Product availability based on booking calendar (hide in WC when no slots available)
- Read-only WC product page notice: *"Managed by Bookwise"* (cuando Bookwise sea fuente de verdad)
