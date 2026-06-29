# Tasks: booking-conflict-fix

## Phase 1: Validation Logic

### Task 1.1 — Remove location overlap check from `checkBookingOverlap()`

**File**: `app/Http/Controllers/Api/V1/BookingController.php`

**Changes**:
- Delete the `$base('location_id', $locationId)` check
- Keep only provider and client checks
- The method signature stays the same (can keep `$locationId` param for now, just don't use it)

**Before**:
```php
return $base('provider_id', $providerId)
    ?? $base('location_id', $locationId)
    ?? $base('client_id', $clientId);
```

**After**:
```php
return $base('provider_id', $providerId)
    ?? $base('client_id', $clientId);
```

**Verify**: No test exists, but manual check — create a booking for Provider A at Location X, then try creating one for Provider B at same Location X overlapping time. Should succeed.

---

## Phase 2: Enriched Conflict Response

### Task 2.1 — Enrich 409 response in `store()` method

**File**: `app/Http/Controllers/Api/V1/BookingController.php` (lines ~146-162)

**Changes**:
- After the `$conflict` is detected, load `client` and `provider` relationships:
  ```php
  $conflict->load(['client', 'provider']);
  ```
- Update the `conflicts_with` array to include `client` and `provider` data
- Update the `$conflictType` match to only handle provider and client (remove location)
- Remove the location-specific case from the match block

**Response structure**:
```php
'conflicts_with' => [
    'id' => $conflict->id,
    'start_time' => $conflict->start_time->toIso8601String(),
    'end_time' => $conflict->end_time->toIso8601String(),
    'client' => [
        'id' => $conflict->client->id,
        'first_name' => $conflict->client->first_name,
        'last_name' => $conflict->client->last_name,
    ],
    'provider' => [
        'id' => $conflict->provider->id,
        'first_name' => $conflict->provider->first_name,
        'last_name' => $conflict->provider->last_name,
    ],
],
```

### Task 2.2 — Enrich 409 response in `update()` method

**File**: `app/Http/Controllers/Api/V1/BookingController.php` (lines ~232-248)

**Changes**: Same as Task 2.1, but applied to the update method's conflict response block.

**Note**: The `update()` method uses local variables `$providerId`, `$locationId`, etc. for the match. After removing location overlap, the match cases only need `$providerId` (for provider overlap) and the default for client overlap. Update the match to:
```php
$conflictType = match (true) {
    $conflict->provider_id === (int) $providerId => 'Provider overlap with existing booking',
    default => 'Client overlap with existing booking',
};
```

---

## Phase 3: Final Review

### Task 3.1 — Run Pint and verify

- Run `vendor/bin/pint --dirty --format agent` to ensure code style
- Do a final read of the changed file to verify all three conflict response blocks are consistent
- Verify the responses in:
  - `store()` — lines ~146-162
  - `update()` — lines ~232-248

---

## Review Workload Forecast

- **Estimated changed lines**: ~35 (additions) + ~10 (deletions) = ~45 lines total
- **Risk**: Low — well-understood change, single file, no new dependencies
- **Decision**: Single PR, no chaining needed
