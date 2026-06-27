# Proposal: Booking No Past Dates

## Intent

Prevent creating or updating bookings with a `start_time` in the past. A booking in the past is impossible to fulfill.

## Scope

### In scope
- Validate in `POST /v1/bookings` that `start_time >= now` (CLT)
- Validate in `PATCH /v1/bookings/{id}` that if `start_time` is being changed, the new value is not in the past
- Return a clear 422 error message when the validation fails

### Out of scope
- Edits to `notes` or `price` on past bookings are allowed (no time change = no validation)
- Slot availability logic (already filters past slots)
- Margins or buffers (user explicitly chose no margin)

## Approach

Add validation in `BookingController`:
1. **`store()`**: After parsing `$startTime`, compare with `now` in CLT. If `$startTime->isPast()`, return 422.
2. **`update()`**: If `start_time` is in the request, parse it and compare with `now`. If past, return 422.

Use `Carbon::now()` which operates in `America/Santiago` timezone.

## Success Criteria

1. Creating a booking with start_time = 1 minute ago → 422 error
2. Creating a booking with start_time = now → 201 success
3. Creating a booking with start_time = 1 hour from now → 201 success
4. Updating a booking's notes (no time change) on a past booking → 200 success
5. Updating a past booking's start_time to another past time → 422 error
6. Updating a past booking's start_time to a future time → 200 success

## Risks
- **None** — simple validation, no side effects.

## Artifacts
- `openspec/booking-no-past-dates/proposal.md`
- Engram topic: `sdd/booking-no-past-dates/proposal`
