## Exploration: Adding `created_via` and `last_modified_via` to the Booking model

### Current State

The Booking model currently has 13 columns in the `bookings` table:

| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| `id` | BIGINT PK | No | Auto-increment |
| `client_id` | BIGINT FK | No | → `clients(id)` |
| `service_id` | BIGINT FK | No | → `services(id)` |
| `provider_id` | BIGINT FK | Yes* | → `providers(id)`, *made nullable by 2026_07_04 migration |
| `location_id` | BIGINT FK | No | → `locations(id)` |
| `status_id` | BIGINT FK | No | → `booking_statuses(id)` |
| `start_time` | DATETIME | No | |
| `end_time` | DATETIME | No | |
| `custom_duration_minutes` | USMALLINT | Yes | Added by 2026_04_24 migration |
| `price` | DECIMAL(10,2) | Yes | |
| `notes` | TEXT | Yes | |
| `wc_order_id` | BIGINT UNSIGNED | Yes | Unique index added by 2026_07_04 migration |
| `created_at` | TIMESTAMP | Yes | Laravel standard |
| `updated_at` | TIMESTAMP | Yes | Laravel standard |
| `deleted_at` | TIMESTAMP | Yes | SoftDeletes trait |

**Model fillable**: `client_id`, `service_id`, `provider_id`, `location_id`, `status_id`, `start_time`, `end_time`, `custom_duration_minutes`, `price`, `notes`, `wc_order_id`

**Model casts**: `start_time => datetime`, `end_time => datetime`, `price => decimal:2`, `custom_duration_minutes => integer`

**No existing `created_via` or `last_modified_via` patterns exist in the codebase.**

---

### Affected Areas

- `app/Models/Booking.php` — Add fillable, casts, and attributes
- `database/migrations/xxxx_create_bookings_table.php` — New migration to add columns
- `app/Http/Controllers/Api/V1/BookingController.php` — Set `created_via` on store, `last_modified_via` on update/cancel
- `app/Http/Controllers/Api/V1/WebhookController.php` — Set `created_via = 'online_webhook'` on webhook creation
- `app/Services/BookingService.php` — Set `created_via` in `findOrCreateBooking()`
- `app/Http/Resources/V1/BookingResource.php` — Include new fields in response
- `app/Http/Resources/BookingResource.php` — (Non-API resource, if used) include new fields
- All seeders creating bookings — No changes needed (defaults can be null)
- Tests — Update assertions in WebhookOrderCompletedTest.php, IdempotencyTest.php

---

### Booking Creation Paths

1. **API Store** (`BookingController::store`) — POST `/api/v1/bookings`
   - Authenticated user (admin/provider/woocommerce)
   - Creates via `Booking::create([...$validated, 'end_time' => ..., 'custom_duration_minutes' => ..., 'price' => ...])`
   - Many fields come from validated request; `start_time` is required, `end_time` optional
   - `created_via` should be `'api'`

2. **Webhook Order Completed** (`WebhookController::handleOrderCompleted`) — POST `/api/v1/webhooks/woocommerce`
   - Via `BookingService::findOrCreateBooking()` which has idempotency check on `wc_order_id`
   - Creates with explicit data (no request validation, transformed from WooCommerce payload)
   - `created_via` should be `'online_webhook'`

3. **Seeders** (several: `TestDataSeeder`, `JuneBookingsSeeder`, `ThisWeekBookingsSeeder`, etc.)
   - Use `DB::table('bookings')->insertGetId([...])` — raw inserts, bypass model
   - These can remain null/default — no `created_via` needed for seed data

4. **Tests** that create bookings directly via model:
   - `IdempotencyTest`: creates like `Booking::create([...])` with all fields
   - `WebhookOrderCompletedTest`: creates via `Booking::create([...])` for test setup
   - These set fields explicitly — can omit the new field (null by default)

---

### Booking Update Paths

1. **API Update** (`BookingController::update`) — PATCH `/api/v1/bookings/{id}`
   - Uses `$booking->update($validated)` with validated fields
   - `last_modified_via` should be `'api'`

2. **API Cancel** (`BookingController::cancel`) — PATCH `/api/v1/bookings/{id}/cancel`
   - Uses `$booking->update(['status_id' => $cancelStatus->id])`
   - `last_modified_via` should be `'api'`

3. **Webhook Refund** (`WebhookController::handleOrderRefunded`)
   - Uses `$booking->update(['status_id' => $cancelStatus->id])`
   - `last_modified_via` should be `'online_webhook'`

4. **Direct model updates in tests/services** — Can remain unchanged (field stays null)

---

### Existing Enum Patterns

The only enum in the codebase is `app/Enums/UserRole.php`:

```php
enum UserRole: string
{
    case ADMIN = 'admin';
    case PROVIDER = 'provider';
    // ...
    public function tokenAbilities(): array
    {
        return match ($this) { ... };
    }
}
```

**Pattern**: String-backed enums with methods. For `created_via`/`last_modified_via`, a similar string-backed enum would be the cleanest approach.

---

### Controller Request/Response Format

**Controller validation**: Uses inline `$request->validate([...])` — **no FormRequest classes exist** for bookings.

**Response format**: `BookingResource` (V1 version) returns:

```json
{
  "id": 1,
  "start_time": "2026-05-04T09:00:00-04:00",
  "end_time": "2026-05-04T10:00:00-04:00",
  "effective_duration_minutes": 60,
  "custom_duration_minutes": null,
  "price": 35000.00,
  "notes": null,
  "wc_order_id": null,
  "created_at": "...",
  "client": { ... },
  "service": { ... },
  "provider": { ... },
  "location": { ... },
  "status_id": 2,
  "status": { "id": 2, "name": "Confirmado", "color": "#fb923c", "is_cancellation": false },
  "payment_status": "unpaid",
  "payment": { ... },
  "pack_session": null
}
```

Both Resource versions (root `app/Http/Resources/` and `app/Http/Resources/V1/`) exist. The **V1 one is the actual API resource** used in controllers. The root one looks like an older version.

---

### Test Patterns

- Uses `LazilyRefreshDatabase` (not `RefreshDatabase`)
- Tests create models directly (no factories used for Booking/Client/etc.)
- `User::factory()->create(['role' => UserRole::ADMIN])` — User factory exists
- `Client::create([...])` with explicit fields
- `Booking::create([...])` with all required fields
- Tests assert with `assertDatabaseHas`, `assertDatabaseCount`, `assertJson`
- Test files: `WebhookOrderCompletedTest.php`, `IdempotencyTest.php`, `ExampleTest.php`
- **No dedicated Booking test file exists** (`tests/Feature/Api/V1/BookingTest.php` doesn't exist)

---

### Approaches

1. **String-backed Enum + Migration** — Recommended
   - Create `app/Enums/BookingSource.php` with cases like `API`, `ONLINE_WEBHOOK`
   - Add migration with `created_via` (nullable string) and `last_modified_via` (nullable string)
   - Add to model fillable and casts (or just fillable since they're strings)
   - Set in controller/webhook/service at the right points
   - Pros: Type-safe, discoverable, matches existing enum pattern, self-documenting
   - Cons: None significant
   - Effort: Low

2. **Simple string constants on model** — Alternative
   - Define constants like `SOURCE_API = 'api'`, `SOURCE_ONLINE_WEBHOOK = 'online_webhook'` on the model
   - Same migration approach
   - Pros: Simpler, fewer files
   - Cons: No type-safety, less discoverable, less consistent with existing enum pattern
   - Effort: Low

---

### Recommendation

**Use approach #1 (String-backed Enum + Migration)**. It's consistent with the existing `UserRole` enum pattern, provides type safety via `BookingSource::API->value`, and is self-documenting for any new creation paths added later.

**Implementation outline**:

1. **Migration**: Add `created_via VARCHAR(50) NULL` and `last_modified_via VARCHAR(50) NULL` after `wc_order_id`
2. **Enum**: `app/Enums/BookingSource.php` with `API = 'api'`, `ONLINE_WEBHOOK = 'online_webhook'`
3. **Model**: Add to `$fillable`, no cast needed
4. **Controller::store**: `'created_via' => BookingSource::API->value`
5. **Controller::update**: `'last_modified_via' => BookingSource::API->value`
6. **Controller::cancel**: `'last_modified_via' => BookingSource::API->value`
7. **BookingService::findOrCreateBooking**: `'created_via' => BookingSource::ONLINE_WEBHOOK->value`
8. **WebhookController::handleOrderRefunded**: `'last_modified_via' => BookingSource::ONLINE_WEBHOOK->value`
9. **Resources**: Add to both `BookingResource` files (V1 and root)

### Risks

- **Existing seeders use raw DB inserts** — They won't set these fields, but that's fine (they'll remain null)
- **Tests create bookings directly** — Need to verify tests continue to pass without setting the new fields (nullable columns, not in fillable for test creates is fine)
- **Non-V1 resource** (`app/Http/Resources/BookingResource.php`) — Must update both resource files for consistency, or confirm the V1 one is the only one in use
- **BookingController uses `$request->all()` via spread** — `...$validated` spreads validated fields only (good), but `created_via` shouldn't come from the request; must be explicitly set by the backend

### Ready for Proposal

Yes. The analysis is complete and the approach is clear. Proceed to SDD proposal with `BookingSource` enum + migration + model changes + controller/service updates + resource updates.
