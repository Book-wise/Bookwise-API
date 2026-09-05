# Archive: booking-no-past-dates

**Status**: ✅ Completed
**Date**: 2026-06-27

## What was done

Added past-date validation to both booking endpoints:

- **POST /v1/bookings**: Rejects with 422 if `start_time` is before now (CLT)
- **PATCH /v1/bookings/{id}**: Rejects with 422 if `start_time` is being changed to a past time
- Non-time edits (notes, price) on past bookings remain allowed

## Files changed

| File | Change |
|------|--------|
| `app/Http/Controllers/Api/V1/BookingController.php` | Added `$startTime->isPast()` checks in `store()` and `update()` |

## Spec delta

None — implemented exactly per spec.
