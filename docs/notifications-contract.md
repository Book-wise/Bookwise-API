---
title: "Contrato de integración — Notificaciones y recordatorios"
type: contract
status: implemented
tags:
  - api
  - frontend
  - notifications
  - carlitox
created: 2026-09-01
updated: 2026-09-01
prs:
  - "#27"
  - "#28"
  - "#29"
  - "#30"
aliases:
  - Contrato notificaciones
  - Notification contract
---

# Contrato de integración — Notificaciones y recordatorios (backend → frontend)

**Estado**: implementado y mergeado en `develop` (PRs #27-#30). Este es el contrato vigente.

> [!info] Notas relacionadas
> - [[index|Índice de documentación]]
> - [[DEPLOY|Deploy]]
> - [[README|README del proyecto]]

## 1. Dónde viven las preferencias

Las preferencias son **por cliente** (no por reserva). El frontend **solo lee y escribe** la configuración. **El envío lo ejecuta el proveedor carlitox** (WhatsApp + Email vía Mailgun); el backend decide, no envía.

## 2. Lectura — `GET /api/v1/clients/{id}`

Respuesta (campos relevantes del `ClientResource`):

```json
{
  "data": {
    "id": 1,
    "first_name": "Juan",
    "email": "juan@mail.com",
    "phone": "+56912345678",
    "active": true,
    "notifications_enabled": true,
    "notification_prefs": {
      "email_new_booking": true,
      "email_booking_confirmation": true,
      "email_booking_cancellation": true,
      "whatsapp_reminder": true,
      "whatsapp_cancellation_confirmation": true
    }
  }
}
```

## 3. Escritura — `PATCH /api/v1/clients/{id}`

Acepta objeto **parcial** — solo se envían los campos cambiados. Los booleans se validan como `boolean`; claves desconocidas dentro de `notification_prefs` devuelven `422`.

```json
{
  "notification_prefs": {
    "whatsapp_reminder": false
  }
}
```

También acepta el master switch: `{"notifications_enabled": false}`.

## 4. Matriz de eventos → canal → timing (la fuente de verdad)

| Flag (campo) | Evento | Canal | Cuándo se envía |
|---|---|---|---|
| `email_new_booking` | Nueva reserva | Email | Inmediato (al crear) |
| `email_booking_confirmation` | Confirmación de reserva | Email | Inmediato (al confirmar) |
| `email_booking_cancellation` | Cancelación de reserva | Email | Inmediato (al cancelar) |
| `whatsapp_reminder` | Recordatorio de reserva | WhatsApp | Programado (24h y 30m antes) |
| `whatsapp_cancellation_confirmation` | Cancelación exitosa | WhatsApp | Automático (apenas se cancela) |

## 5. UI recomendada — 5 toggles planos (Opción A)

Cada toggle = 1 flag, 1:1 con el contrato. Agrupación visual libre (3 email arriba, 2 WhatsApp abajo), pero **sin mapear ni inventar toggles**.

- **No existe "citaWa"** (WhatsApp inmediato al crear) — fue descartado. WhatsApp cubre recordatorio + cancelación exitosa.
- Los toggles se **inicializan desde el GET** — no en `false` por defecto en memoria.
- `reminder_24h_sent_at` / `reminder_30m_sent_at` (si aparecen en bookings) son timestamps de "ya se envió", **NO toggles**.

## 6. Endpoints de integración con carlitox (NO los usa el front — solo informativo)

Estos son para el agente conversacional, con scopes propios (`notifications:read` / `notifications:write`). El front no los llama:

```
GET  /api/v1/notifications/pending?channel=whatsapp&type=reminder
POST /api/v1/notifications/reminders/ack    { "booking_id": 1, "reminder_type": "24h" | "30m" }
```
