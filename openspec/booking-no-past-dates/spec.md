# Specification: Booking No Past Dates

## Purpose

Ensure bookings cannot be created or rescheduled to a time that has already passed.

---

## Requirements

### R1: New bookings must not be in the past

`POST /v1/bookings` MUST reject the request if `start_time` is before the current moment in America/Santiago timezone.

**detail**: `"Cannot create a booking in the past."`
**error**: `"past_booking"`

### R2: Updated bookings must not be moved to the past

`PATCH /v1/bookings/{id}` MUST reject the request if `start_time` is provided and its value is before the current moment in America/Santiago timezone.

**detail**: `"Cannot move a booking to the past."`
**error**: `"past_booking"`

### R3: Non-time edits on past bookings are allowed

If the PATCH request does not include `start_time`, the validation is NOT triggered. Notes, price, and status changes are allowed on past bookings.

### R4: No margin

The comparison is strict: `now` vs `start_time`. Zero margin. If `start_time` equals `now` (same second), it's valid.

---

## Scenarios

### Scenario 1: New booking in the past → 422

**Given**: Current time is 2026-06-27T14:00:00-04:00
**When**: POST /bookings with start_time = 2026-06-27T13:59:00-04:00
**Then**: 422 with `"Cannot create a booking in the past."`

### Scenario 2: New booking at current time → 201

**Given**: Current time is 2026-06-27T14:00:00-04:00
**When**: POST /bookings with start_time = 2026-06-27T14:00:00-04:00
**Then**: 201 Created

### Scenario 3: Update notes on past booking → 200

**Given**: Booking exists with start_time = 2026-06-25T10:00:00-04:00 (past)
**When**: PATCH /bookings/{id} with { notes: "New note" } (no start_time)
**Then**: 200 OK

### Scenario 4: Update time to past on past booking → 422

**Given**: Booking exists with start_time = 2026-06-25T10:00:00-04:00
**When**: PATCH /bookings/{id} with { start_time: "2026-06-25T09:00:00-04:00" }
**Then**: 422 with `"Cannot move a booking to the past."`

### Scenario 5: Update time to future on past booking → 200

**Given**: Booking exists with start_time = 2026-06-25T10:00:00-04:00
**When**: PATCH /bookings/{id} with { start_time: "2026-06-28T10:00:00-04:00" }
**Then**: 200 OK

---

## API Contract

### 422 Response

```json
{
    "error": "past_booking",
    "detail": "Cannot create a booking in the past."
}
```

or for updates:

```json
{
    "error": "past_booking",
    "detail": "Cannot move a booking to the past."
}
```
