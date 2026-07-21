# Contrato API v1 — estabilización

Las respuestas de recursos individuales usan `{ "data": { ... } }`. Las colecciones paginadas conservan el envelope de Laravel Resource: `{ "data": [ ... ], "links": { ... }, "meta": { ... } }`.

## Endpoints estabilizados

| Endpoint | Acceso | Respuesta |
| --- | --- | --- |
| `GET /api/v1/bookings` | `bookings:read`; provider limitado a su ubicación, admin total | colección paginada de `BookingResource` |
| `GET /api/v1/bookings/{id}` | `bookings:read`; mismo ownership | `{data: BookingResource}` |
| `POST /api/v1/bookings` | `bookings:write`; provider sólo en su ubicación | `{data: BookingResource}` (201) |
| `PATCH /api/v1/bookings/{id}` | `bookings:write`; provider/admin y ownership | `{data: BookingResource}` |
| `PATCH /api/v1/bookings/{id}/cancel` | `bookings:write` y ownership | `{data: BookingResource}` |
| `POST /api/v1/services` | token admin | `{data: ServiceResource}` (201) |
| `GET /api/v1/available_slots` | público | `{data: [...], meta: {...}}` |
| `POST /api/v1/webhooks/woocommerce` | HMAC WooCommerce | respuesta técnica sin Resource |

`POST /register` no forma parte del contrato. Los endpoints de servicios públicos `GET /services` y `GET /services/{id}` se mantienen.

## Errores relevantes

- `401 {error: "unauthorized"}`: firma HMAC inválida.
- `503 {error: "configuration_error"}`: falta el secreto del webhook.
- `403`: token, rol u ownership insuficiente.
- `409 {error: "conflict"}`: solapamiento con reserva, cliente o bloqueo de agenda; `conflicts_with` identifica el registro.
- `422`: validación Laravel o estado no permitido.

## Paginación y filtros

`GET /bookings` y `GET /services` aceptan `per_page`; las reservas además conservan sus filtros actuales (`client_id`, `service_id`, `provider_id`, `location_id`, `status_id`, `date_from`, `date_to`, `wc_order_id`). Los filtros no amplían el alcance de ownership.

## Scopes

Los scopes se validan mediante Sanctum. `admin` conserva `*`; `provider` conserva sus scopes actuales. Esta fase no cambia los scopes de Carlitox ni le permite escritura de reservas.
