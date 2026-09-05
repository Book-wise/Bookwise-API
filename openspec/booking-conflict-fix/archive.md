# Archive: booking-conflict-fix

**Status**: ✅ Completed
**Date**: 2026-06-27

## What was done

### Change 1: Remove location overlap as blocking check
- Removed `$base('location_id', $locationId)` from `checkBookingOverlap()`
- Validation now checks only: provider overlap → client overlap
- Multiple providers at the same location can work simultaneously

### Change 2: Enriched 409 conflict response
- `store()` and `update()` now load `$conflict->load(['client', 'provider'])` before returning 409
- Response includes `client` (id, first_name, last_name) and `provider` (id, first_name, last_name)

### Change 3: Timezone standardization to CLT
- `config/app.php` timezone changed from `UTC` to `America/Santiago`
- All bookings are stored and displayed in Chile local time
- Responses now show `-04:00` / `-03:00` offset instead of `+00:00`

## Files changed

| File | Change |
|------|--------|
| `app/Http/Controllers/Api/V1/BookingController.php` | Removed location overlap from `checkBookingOverlap()`; enriched 409 response in `store()` and `update()` |
| `config/app.php` | `'timezone' => 'America/Santiago'` |

## No migration needed
Existing seed data values were already intended as CLT times. The timezone config change interprets them correctly without data migration.

## Frontend notes
Times must be sent without `Z` suffix (local CLT) or with CLT offset.

## Spec delta
Original spec assumed UTC. After implementation, all times use America/Santiago. No behavioral impact on the validation logic.
