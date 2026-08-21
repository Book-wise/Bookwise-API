# Booking Source Tracking — Specification

## Purpose

Track how each booking was created and last modified (admin calendar, conversational agent, or WooCommerce webhook) so the frontend can display origin labels and audit booking flow.

---

## Requirements

| # | Area | Requirement |
|---|------|-------------|
| R1 | Enum | `App\Enums\BookingSource` — string-backed. Cases: `AdminCalendar = 'admin_calendar'`, `Agent = 'agent'`, `OnlineWebhook = 'online_webhook'` |
| R2 | Migration | Add nullable `varchar(40)` `created_via` and `last_modified_via` after `wc_order_id`. No backfill. |
| R3 | Model | Both columns in `$fillable`. Both cast to `BookingSource` (nullable). |
| R4 | POST /v1/bookings | After validation: admin → `created_via = last_modified_via = 'admin_calendar'`; agent → `'agent'`. Derived from `$request->user()->role`. |
| R5 | PATCH /v1/bookings/{id} | Set `last_modified_via` per role. `created_via` MUST NOT change. |
| R6 | PATCH cancel | Same as R5 — set `last_modified_via` per role. `created_via` MUST NOT change. |
| R7 | BookingService | `findOrCreateBooking()` accepts optional `?BookingSource $createdVia = OnlineWebhook`. Sets source only on creation, never on replay. |
| R8 | Webhook refund | Set `last_modified_via = 'online_webhook'` on the booking. `created_via` MUST NOT change. |
| R9 | API Resource | `created_via` always present as string. `last_modified_via` present only when not null. |
| R10 | Token ability | `UserRole::AGENT->tokenAbilities()` includes `'bookings:write'`. |
| R11 | Route auth | `PATCH /v1/bookings/{id}/cancel` requires `scope:bookings:write` AND `role:admin,agent`. |
| R12 | Seeders | All booking seeders set `created_via` to a plausible source value. |
| R13 | Immutability | `created_via` is write-once — never modified after creation. Only `last_modified_via` updates. |

---

## Scenarios

### S1: Admin creates booking
- GIVEN an authenticated admin user
- WHEN they POST valid data to `/v1/bookings`
- THEN `created_via` = `last_modified_via` = `'admin_calendar'`

### S2: Agent creates booking
- GIVEN an authenticated agent user
- WHEN they POST valid data to `/v1/bookings`
- THEN `created_via` = `last_modified_via` = `'agent'`

### S3: Webhook creates booking
- GIVEN a valid `order.completed` webhook
- WHEN it creates a new booking
- THEN `created_via` = `last_modified_via` = `'online_webhook'`

### S4: Webhook replay does not overwrite
- GIVEN a booking with `created_via = 'admin_calendar'`
- WHEN the same `order.completed` webhook replays
- THEN both source fields remain unchanged

### S5: Admin modifies booking
- GIVEN a booking with `created_via = 'online_webhook'`
- WHEN an admin PATCHes it
- THEN `last_modified_via` = `'admin_calendar'`; `created_via` stays `'online_webhook'`

### S6: Agent cancels booking
- GIVEN a booking with `created_via = 'admin_calendar'`
- WHEN an agent cancels via `PATCH /v1/bookings/{id}/cancel`
- THEN `last_modified_via` = `'agent'`; `created_via` stays

### S7: Webhook refund sets last_modified_via
- GIVEN a booking with `created_via = 'admin_calendar'`
- WHEN a refund webhook cancels it
- THEN `last_modified_via` = `'online_webhook'`

### S8: API response exposes source fields
- GIVEN any booking
- WHEN fetched via GET `/v1/bookings` or `/{id}`
- THEN response has `created_via` (string) and, if non-null, `last_modified_via` (string)

### S9: Agent cancels with token ability
- GIVEN an agent with a fresh token
- WHEN they PATCH `/v1/bookings/{id}/cancel`
- THEN scope + role middleware pass; booking is cancelled

### S10: Existing records have null source
- GIVEN a booking created before this migration
- WHEN fetched via API
- THEN `created_via` is null (omitted from response)

### S11: Seeded data has created_via
- GIVEN seeded booking records
- WHEN inspected in DB
- THEN each has a non-null `created_via`

---

## Constraints

- Columns nullable — null = pre-existing record, no backfill
- `created_via` write-once immutable; `last_modified_via` updates on every mutation
- Enum values match frontend display mapping — no server-side translation needed
