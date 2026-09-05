# Tasks: booking-no-past-dates

## Phase 1: Validation

### Task 1.1 — Past-date validation in `store()`

**File**: `app/Http/Controllers/Api/V1/BookingController.php`

After `$startTime = Carbon::parse($validated['start_time'])` (line 126), add:

```php
if ($startTime->isPast()) {
    return response()->json([
        'error' => 'past_booking',
        'detail' => 'Cannot create a booking in the past.',
    ], 422);
}
```

### Task 1.2 — Past-date validation in `update()`

**File**: `app/Http/Controllers/Api/V1/BookingController.php`

Inside the overlap check block, after `$startTime = ...` (line 222), if `start_time` was provided in the request:

```php
if (isset($validated['start_time']) && $startTime->isPast()) {
    return response()->json([
        'error' => 'past_booking',
        'detail' => 'Cannot move a booking to the past.',
    ], 422);
}
```

## Phase 2: Final Review

### Task 2.1 — Pint + verification

- Run `vendor/bin/pint --dirty --format agent`
- Run tests
- Review both endpoints

## Review Workload Forecast

- **Estimated lines**: ~20 additions
- **Risk**: None
- **Decision**: Single PR
