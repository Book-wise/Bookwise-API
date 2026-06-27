# Specification: Booking Conflict Fix

## Purpose

Refine the booking overlap validation to reflect the real business model (multiple providers per location) and enrich the conflict response with meaningful data.

---

## Requirements

### R1: Location overlap must not block bookings

The system MUST NOT check for location-based overlaps when validating a new or updated booking. Two bookings at the same location with overlapping times are allowed as long as they involve different providers and different clients.

**Rationale**: A location represents a shared workspace where multiple providers attend patients simultaneously. Location is not a limited resource.

### R2: Provider overlap must block bookings

The system MUST block a booking if the same provider already has an active booking with an overlapping time range (regardless of location).

**Rationale**: A provider can only attend one patient at a time.

### R3: Client overlap must block bookings

The system MUST block a booking if the same client already has an active booking with an overlapping time range (regardless of location).

**Rationale**: A client cannot be in two appointments simultaneously.

### R4: Conflicting booking details in 409 response

When a 409 conflict is returned, the response MUST include the client name and provider name of the conflicting booking, in addition to the existing `id`, `start_time`, and `end_time`.

### R5: Conflict type must match the actual conflict

The `detail` field must accurately describe the conflict type:
- `"Provider overlap with existing booking"` — when the same provider has an overlapping booking
- `"Client overlap with existing booking"` — when the same client has an overlapping booking

No "Location overlap" detail should exist.

---

## Scenarios

### Scenario 1: Different providers, same location, overlapping time → ALLOWED

**Given**:
- Provider A has an active booking at Location X from 12:00 to 13:00
- Provider B is available

**When**: Creating a booking for Provider B at Location X from 12:00 to 13:00

**Then**: The booking is created successfully (201 Created)

### Scenario 2: Same provider, any location, overlapping time → 409 BLOCKED

**Given**:
- Provider A has an active booking from 12:00 to 13:00
- Client B requests a booking with Provider A from 12:30 to 13:30

**When**: Validating the overlap

**Then**:
- Response: 409 Conflict
- `detail`: "Provider overlap with existing booking"
- `conflicts_with.client`: includes the name of the client from the existing booking
- `conflicts_with.provider`: includes Provider A's name

### Scenario 3: Same client, overlapping time → 409 BLOCKED

**Given**:
- Client C has an active booking from 12:00 to 13:00 with Provider A
- Client C requests a booking from 12:30 to 13:30 with Provider B

**When**: Validating the overlap

**Then**:
- Response: 409 Conflict
- `detail`: "Client overlap with existing booking"
- `conflicts_with.client`: includes Client C's name
- `conflicts_with.provider`: includes the provider name from the existing booking

### Scenario 4: Same provider, no overlap → ALLOWED

**Given**:
- Provider A has an active booking from 12:00 to 13:00
- Client D requests a booking with Provider A from 13:00 to 14:00

**When**: Validating the overlap (`start_time < 13:00 AND end_time > 13:00`)

**Then**: No overlap detected (adjacent times are NOT overlapping). Booking created.

### Scenario 5: Update booking — no time/provider change → NO OVERLAP CHECK

**Given**:
- Booking B exists with start 12:00, end 13:00, provider A, location X, client C
- Only `notes` is being updated

**When**: PATCH /bookings/{id}

**Then**: No overlap check is performed. Booking updated successfully.

### Scenario 6: Update booking — time change causing provider conflict → 409 BLOCKED

**Given**:
- Booking #1: Provider A, 12:00–13:00
- Booking #2 (being updated): Provider A, 14:00–15:00, changing start to 12:30

**When**: PATCH /bookings/{id} with new start_time=12:30

**Then**:
- Response: 409 Conflict
- `detail`: "Provider overlap with existing booking"

---

## API Contract

### 409 Conflict Response

```json
{
    "error": "conflict",
    "detail": "Provider overlap with existing booking",
    "conflicts_with": {
        "id": 216,
        "start_time": "2026-06-23T12:00:00+00:00",
        "end_time": "2026-06-23T13:00:00+00:00",
        "client": {
            "id": 9,
            "first_name": "Isidoro",
            "last_name": "Muñoz"
        },
        "provider": {
            "id": 1,
            "first_name": "María",
            "last_name": "González"
        }
    }
}
```

### Possible `detail` values

| Value | Condition |
|-------|-----------|
| `"Provider overlap with existing booking"` | Same provider, overlapping time |
| `"Client overlap with existing booking"` | Same client, overlapping time |
