# Proposal: Booking Conflict Fix

## Intent

Improve the booking conflict validation logic so that:
1. Location-based overlaps no longer block bookings — only provider and client overlaps should trigger a 409 conflict.
2. When a conflict does occur, the API response includes the names of the patient and provider involved in the conflicting booking.

## Motivation

The current `checkBookingOverlap()` checks three conditions in order: provider → location → client. The location check assumes the entire location is a single resource, but in practice multiple providers work simultaneously at the same location (e.g., Kinesilk Centro has 3 providers). This means a booking attempt with a free provider gets incorrectly blocked because another provider has a booking at the same location/time.

Additionally, the 409 response only returns `id`, `start_time`, `end_time` of the conflicting booking — the caller has no way to identify the patient or provider involved without an extra API call.

## Scope

### In scope
- Remove the location overlap check from `checkBookingOverlap()` so it only validates provider and client conflicts.
- Update the `store()` and `update()` methods in `BookingController` to load and include `client` (id, first_name, last_name) and `provider` (id, first_name, last_name) in the `conflicts_with` response.
- Update all three resulting 409 responses (store, update, and the match true logic) to reflect only provider and client conflict types.

### Out of scope
- Tests for the overlap validation (will be handled separately).
- Changes to `BlockedSlotController` overlap validation (separate concern).
- Adding capacity or room tracking to locations.

## Approach

1. **In `checkBookingOverlap()`**: Remove the `$base('location_id', $locationId)` check, keeping only provider and client checks.
2. **In `store()` and `update()`**: Before building the 409 response, load `$conflict->load(['client', 'provider'])` on the conflicting booking, then include `client` and `provider` data in `conflicts_with`.
3. **Update `detail` messages**: Only "Provider overlap with existing booking" and "Client overlap with existing booking" remain.

## Success Criteria

1. A booking can be created for Provider A at Location X during a time when Provider B has an active booking at the same Location X.
2. A booking cannot be created for Provider A if Provider A already has an overlapping active booking (any location).
3. A booking cannot be created for Client C if Client C already has an overlapping active booking (any location).
4. The 409 response for a provider conflict includes the conflicting client's name and the conflicting provider's name.
5. The 409 response for a client conflict includes the conflicting booking's provider name.

## Risks

- **Low**: This changes existing API behavior. Clients relying on location-overlap blocking may now allow bookings they previously didn't. This is the intended behavior per business requirements.
- **Low**: Exposing patient names in error responses could be a privacy concern, but the user explicitly approved full name exposure.

## Artifacts

- `openspec/booking-conflict-fix/proposal.md` — this document
- Engram topic: `sdd/booking-conflict-fix/proposal`
