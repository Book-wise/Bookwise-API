# Timezone Configurable — Design Doc

**Date:** 2026-07-17
**Version:** 2.0
**Status:** Proposal — approved for implementation
**Author:** Gentle AI — Bookwise Orchestrator

---

## 1. Problem

Bookwise maneja reservas en múltiples sucursales (locations) que pueden estar en distintas regiones con diferentes husos horarios y reglas de DST (Daylight Saving Time).

### Caso real: Punta Arenas vs Santiago

Punta Arenas (Región de Magallanes) mantiene **UTC-3 todo el año** — no participa del cambio de hora. Santiago alterna entre **UTC-3 (verano)** y **UTC-4 (invierno)** siguiendo el DST estándar de Chile.

| Periodo | Santiago | Punta Arenas | Diferencia |
|---|---|---|---|
| Septiembre → Abril (verano) | UTC-3 | UTC-3 | 0 |
| Abril → Septiembre (invierno) | UTC-4 | UTC-3 | Punta Arenas +1h |

**Fechas del cambio (2026–2028):**

| Año | Inicio diferencia (Santiago atrasa) | Término diferencia (Santiago adelanta) |
|---|---|---|
| 2026 | Sábado 4 de abril | Sábado 5 de septiembre |
| 2027 | Sábado 3 de abril | Sábado 4 de septiembre |
| 2028 | Sábado 1 de abril | Sábado 2 de septiembre |

---

## 2. Strategic Decisions

### 2.1 IANA Timezone Identifiers

No implementamos lógica de DST custom. PHP/Carbon ya maneja esto con las IANA timezones:

| Timezone | Comportamiento |
|---|---|
| `America/Santiago` | DST estándar de Chile (UTC-3 ↔ UTC-4) |
| `America/Punta_Arenas` | UTC-3 fijo todo el año (sin DST) |

La clase `DateTimeZone` + `Carbon` resuelve automáticamente el offset correcto para cualquier fecha.

### 2.2 Source of Truth

- **Location.timezone** se almacena en BD por sucursal (ya existe el campo)
- **Admin** lo configura al crear/editar una location (gestión admin)
- **available_timezones** es estático — se define en configuración del backend
- **default_timezone** se define en `config/app.php`

### 2.3 Time Storage Convention

- **Slots**: se almacenan en UTC (ISO 8601)
- **Booking.start_time / end_time**: se almacenan en UTC (ya es así)
- **Location.opening_time / closing_time**: son TIME locales a la sucursal (se interpretan según `location.timezone`)

### 2.4 Decisión Crítica: Backend interpreta hora local (NO Frontend)

**El frontend envía hora local. El backend interpreta usando location.timezone.**

Esto es por seguridad empresarial:

| Aspecto | Frontend convierte a UTC | Backend interpreta local |
|---|---|---|
| Punto único de falla | ❌ Múltiples frontends | ✅ Un solo backend |
| Integraciones de terceros | ❌ Cada una debe implementar conversión UTC | ✅ Funciona sin cambios |
| Riesgo de datos corruptos | ❌ Un bug en frontend → datos malos en BD | ✅ Backend es la última milla |
| Mantenibilidad | ❌ Cada frontend nuevo debe saber de UTC | ✅ Solo el backend conoce timezones |

---

## 3. Architecture

### 3.1 Contrato Frontend-Backend

```
Frontend                          Backend
   │                                │
   ├─ GET /api/v1/config ──────────►│  ← default_timezone + available_timezones
   │                                │
   ├─ GET /api/v1/locations ───────►│  ← cada location trae su timezone
   │                                │
   │   Usuario selecciona sucursal  │
   │   Frontend obtiene timezone    │
   │                                │
   ├─ GET /api/v1/available_slots ─►│  ← calculado con timezone de la location
   │   ?location_id=2              │
   │                                │
   │   Slots en UTC ←───────────────┤
   │   Frontend CONVIERTE UTC →     │
   │   hora local (solo visual)     │
   │                                │
   ├─ POST /v1/bookings ───────────►│
   │   {                            │
   │     start_time: "09:00",      │  ← hora LOCAL (lo que ve el usuario)
   │     location_id: 2,           │  ← backend usa location.timezone para interpretar
   │     duration_minutes: 60      │
   │   }                            │
   │                                │
   │   Backend:                     │
   │   location=2 → timezone=       │
   │   "America/Punta_Arenas" →     │
   │   09:00 CLT = 12:00 UTC ✓     │
```

### 3.2 Sobre las respuestas de bookings

Cuando el backend devuelve un booking existente:

```json
{
  "data": {
    "id": 123,
    "start_time": "2026-07-18T12:00:00Z",
    "location_id": 2,
    "location": {
      "timezone": "America/Punta_Arenas"
    }
  }
}
```

El frontend debe convertir `start_time` (UTC) al timezone de la location usando `Intl.DateTimeFormat` o similar — **solo para mostrar**. Siempre extrae y envía la hora UTC que recibe.

---

## 4. Backend Changes

### 4.1 `GET /api/v1/config` (NUEVO)

Endpoint público sin autenticación, mismo patrón que `GET /api/v1/services`.

**Respuesta:**
```json
{
  "default_timezone": "America/Santiago",
  "available_timezones": [
    {
      "id": "America/Santiago",
      "name": "Santiago (UTC-3 / UTC-4)",
      "has_dst": true
    },
    {
      "id": "America/Punta_Arenas",
      "name": "Punta Arenas (UTC-3 permanente)",
      "has_dst": false
    }
  ]
}
```

**Componentes:**
- `App\Http\Controllers\Api\V1\ConfigController` — nuevo
- Ruta: `GET /v1/config` en grupo público con `throttle:api_public`
- Lectura de `config('app.timezone')`
- Lista de timezones desde un archivo config (`config/timezones.php`)

### 4.2 SlotAvailabilityService (MODIFICADO)

El servicio actual no usa el timezone de la location al calcular slots:

```php
// 🔴 Actual — ignora timezone:
$dayStart = $date->copy()->setTimeFromTimeString($location->opening_time);

// ✅ Corregido — usa timezone de la location:
$locationTz = new \DateTimeZone($location->timezone);
$dayStart = Carbon::parse($date->format('Y-m-d'), $locationTz)
    ->setTimeFromTimeString($location->opening_time);
```

**Impacto del cambio:**
- Un slot de las 09:00 en Punta Arenas equivale a 12:00 UTC todo el año
- Un slot de las 09:00 en Santiago equivale a 12:00 UTC en verano, 13:00 UTC en invierno
- Los bookings existentes en BD ya están en UTC — no requieren migración

### 4.3 BookingController store (MODIFICADO)

El controller debe interpretar `start_time` con el timezone de la location:

```php
// ✅ CORREGIDO
$location = Location::findOrFail($validated['location_id']);
$timezone = new \DateTimeZone($location->timezone);

$startTime = Carbon::parse($validated['start_time'], $timezone);
// Si location.timezone = America/Punta_Arenas y start_time = "09:00"
// → Carbon entiende que son las 09:00 en Punta Arenas (UTC-3)
// → Al guardar en BD: 2026-07-18 12:00:00 UTC
```

### 4.4 Location (SIN CAMBIOS)

El modelo `Location` ya tiene campo `timezone` y se expone en `LocationResource`. No requiere modificaciones.

### 4.5 Cache

- `GET /api/v1/config`: Sin caché en v1 (lectura ínfima, datos estáticos)
- Slots: Sin caché (los slots cambian con cada booking)
- Ubicaciones: Sin caché adicional (ya usa paginación estándar)

---

## 5. Frontend Contract

### 5.1 Lo que el frontend NO hace

- ❌ NO convertir horas a UTC antes de enviar
- ❌ NO tener lógica de timezone en los payloads de POST/PATCH
- ❌ NO parsear ni formatear fechas con timezone al enviar datos

### 5.2 Lo que el frontend SÍ hace

**Al mostrar slots disponibles (GET /api/v1/available_slots):**

Los slots vienen en UTC. El frontend los convierte a la hora local de la sucursal seleccionada para mostrarlos al usuario:

```javascript
// CONVERTIR UTC → LOCAL (solo para mostrar)
const formatter = new Intl.DateTimeFormat('es-CL', {
  timeZone: selectedLocationTimezone,
  hour: '2-digit',
  minute: '2-digit',
  hour12: false
});
const localTime = formatter.format(new Date(slot.start)); // "2026-07-18T12:00:00Z" → "09:00"
```

**Al seleccionar un slot desde el calendario/slot picker:**

El frontend toma el `start_time` del slot seleccionado (en UTC) y lo envía tal cual:
```json
POST /v1/bookings
{
  "client_id": 11,
  "location_id": 2,
  "status_id": 1,
  "start_time": "2026-07-18T12:00:00Z",  // ← UTC, tal cual viene del backend
  "duration_minutes": 60
}
```

**Al crear booking desde el calendario admin (diálogo manual):**

El usuario elige fecha, hora y sucursal. Se envía la hora local:
```json
POST /v1/bookings
{
  "client_id": 11,
  "location_id": 2,
  "status_id": 1,
  "start_time": "2026-07-18 09:00",     // ← hora local (lo que ve el usuario)
  "duration_minutes": 60
}
// El backend interpreta "09:00" usando location.timezone = "America/Punta_Arenas"
// → 12:00 UTC
```

**Al mostrar bookings existentes (GET /v1/bookings/{id}):**

```json
{
  "data": {
    "id": 123,
    "start_time": "2026-07-18T12:00:00Z",
    "location_id": 2,
    "location": {
      "timezone": "America/Punta_Arenas"
    }
  }
}
```

El frontend convierte UTC → local para mostrar al usuario:

```javascript
const bookingTimezone = booking.location.timezone; // "America/Punta_Arenas"
const formatter = new Intl.DateTimeFormat('es-CL', {
  timeZone: bookingTimezone,
  hour: '2-digit',
  minute: '2-digit',
  hour12: false,
  year: 'numeric',
  month: '2-digit',
  day: '2-digit'
});
const localTime = formatter.format(new Date(booking.start_time));
```

### 5.3 Dónde aplicar el timezone en el frontend

| Componente | Acción |
|---|---|
| **Config inicial** | `GET /api/v1/config` al cargar la app |
| **Slot picker** (página de producto) | Convertir slots UTC → hora local de la sucursal seleccionada |
| **Diálogo crear booking** (admin) | Enviar hora local sin convertir |
| **Confirmación de reserva** | Mostrar hora en timezone de la sucursal |
| **Mis Sesiones** (Mi Cuenta WC) | Mostrar horas agendadas en timezone de la sucursal |
| **Admin / calendario** | Mostrar en timezone de la sucursal |
| **POST /v1/bookings** | Enviar `start_time` en UTC (si viene de slot) o local (si es manual) |

### 5.4 Resumen de formato por operación

| Operación | Entrada esperada | Ejemplo |
|---|---|---|
| **GET slots** (respuesta) | UTC | `"2026-07-18T12:00:00Z"` |
| **Mostrar slot** | Local (convertido) | `"09:00"` |
| **POST booking (desde slot)** | UTC | `"2026-07-18T12:00:00Z"` |
| **POST booking (desde diálogo)** | Local | `"2026-07-18 09:00"` |
| **GET booking** (respuesta) | UTC | `"2026-07-18T12:00:00Z"` |
| **Mostrar booking** | Local (convertido) | `"18/07/2026 09:00"` |

---

## 6. Implementation Order

| # | Tarea | Dependencia |
|---|---|---|
| 1 | Crear `config/timezones.php` con lista de timezones soportados | — |
| 2 | Crear `ConfigController` + ruta `GET /api/v1/config` | — |
| 3 | Modificar `SlotAvailabilityService` para usar timezone de location | — |
| 4 | Modificar `BookingController@store` para interpretar hora local con location.timezone | #3 |
| 5 | Tests de timezone en SlotAvailabilityService | #3 |
| 6 | Tests de booking con timezone en BookingController | #4 |
| 7 | Tests de ConfigController | #2 |
| 8 | Seeder: Location Punta Arenas + Provider + schedules | — |
| 9 | Frontend: adaptar conversiones para mostrar (no enviar) en local | #1-7 |

---

## 7. Testing Strategy

### Backend tests
- `GET /api/v1/config` retorna 200 con estructura esperada
- `GET /api/v1/config` retorna available_timezones con ambos timezones
- SlotAvailabilityService: mock location con `timezone=America/Punta_Arenas`, verificar que el offset UTC es correcto en abril vs septiembre
- SlotAvailabilityService: mock location con `timezone=America/Santiago`, verificar DST
- BookingController: enviar start_time local + location_id → booking guardado en UTC correcto
- BookingController: enviar start_time UTC + location_id → booking guardado correctamente
- BookingController: horario de Punta Arenas en abril (1h de diferencia con Santiago)
- BookingController: horario de Punta Arenas en enero (misma hora que Santiago)

---

## 8. Rollback Plan

- Revertir commit del ConfigController y ruta
- Revertir cambios en SlotAvailabilityService
- Revertir cambios en BookingController
- No hay migraciones de BD involucradas
- Datos existentes no se modifican

---

## 9. Future Iterations

- Más timezones además de Santiago y Punta Arenas
- Cache con invalidation al editar Location
- Admin UI para cambiar timezone de una location (desde el frontend)
- Que el default_timezone se configure desde admin (no solo en .env)
- Timezone-aware reminders y notificaciones
