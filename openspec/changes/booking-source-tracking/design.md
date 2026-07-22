# Design: Booking Source Tracking

## Technical Approach

Add two nullable source-tracking columns to the `bookings` table (`created_via`, `last_modified_via`), backed by a string-backed `BookingSource` enum. Inject source values at every mutation point (controller store/update/cancel, webhook handler, BookingService) based on the authenticated user's role. Expose both fields in `BookingResource`. Extend agent token abilities and cancel-route middleware to gate agent access.

## Architecture Decisions

| Option | Alternatives | Decision |
|--------|-------------|----------|
| **BookingSource backed enum** vs plain string constants | String constants avoid enum file but lose type safety, autocomplete, and cast support | **Enum** — matches existing pattern (`UserRole`), enables nullable cast on model |
| **Role-based detection pattern** using `$request->user()->role` | Could pass source as request field, but that lets clients lie about origin | **Server-side only** — `admin`/`provider` → `admin_calendar`, `agent` → `agent`, fallback → `admin_calendar` |
| **Nullable exposes `created_via` always as string, `last_modified_via` when not null** | Could expose both always (null emitted) | **Per spec R9** — `created_via` always present, `last_modified_via` conditional |
| **Write-once `created_via`** | Could allow overwrite on webhook replay | **Never modified** after creation — `last_modified_via` is the update-only field |

## Data Flow

```
┌─────────────────────────────────────────────────────────────┐
│  POST /v1/bookings (store)                                  │
│  $request->user()->role → BookingSource → created_via       │
│                                       → last_modified_via   │
│  Both set to same value at creation                         │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│  PATCH /v1/bookings/{id} (update)                           │
│  $request->user()->role → BookingSource → last_modified_via │
│  created_via: UNCHANGED                                     │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│  PATCH /v1/bookings/{id}/cancel                             │
│  $request->user()->role → BookingSource → last_modified_via │
│  created_via: UNCHANGED                                     │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│  Webhook: order.completed                                   │
│  BookingService::findOrCreateBooking(createdVia: Online)    │
│  → created_via = last_modified_via = 'online_webhook'      │
│  Webhook replay: returns existing booking, NO update        │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│  Webhook: order.refunded                                    │
│  Booking::update(['status_id' => ...,                      │
│                   'last_modified_via' => 'online_webhook']) │
│  created_via: UNCHANGED                                     │
└─────────────────────────────────────────────────────────────┘
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `app/Enums/BookingSource.php` | Create | String-backed enum: `AdminCalendar`, `Agent`, `OnlineWebhook` |
| `database/migrations/xxxx_add_created_via_and_last_modified_via_to_bookings_table.php` | Create | Add nullable `varchar(40)` columns after `wc_order_id` |
| `app/Models/Booking.php` | Modify | Add `created_via`, `last_modified_via` to `$fillable` + `$casts` as `BookingSource` (nullable) |
| `app/Http/Controllers/Api/V1/BookingController.php` | Modify | `store()`: set both fields from role. `update()`/`cancel()`: set `last_modified_via` only |
| `app/Services/BookingService.php` | Modify | `findOrCreateBooking()`: accept `?BookingSource $createdVia = BookingSource::OnlineWebhook`, set on creation |
| `app/Http/Controllers/Api/V1/WebhookController.php` | Modify | Pass `BookingSource::OnlineWebhook` to `findOrCreateBooking()`. Set `last_modified_via` in `handleOrderRefunded()` |
| `app/Http/Resources/V1/BookingResource.php` | Modify | Add `created_via` (string) and `last_modified_via` (whenLoaded, nullable) to response array |
| `app/Enums/UserRole.php` | Modify | Add `'bookings:write'` to `AGENT->tokenAbilities()` |
| `routes/api.php` | Modify | Add `->middleware('role:admin,agent')` to cancel route (line 54) |
| `database/seeders/TestDataSeeder.php` | Modify | Add `'created_via' => 'admin_calendar'` to booking inserts |
| `database/seeders/ThisWeekBookingsSeeder.php` | Modify | Add `'created_via' => 'admin_calendar'` to booking inserts |
| `database/seeders/JuneBookingsSeeder.php` | Modify | Add `'created_via' => 'admin_calendar'` to booking inserts |
| `database/seeders/PackBookingsThisWeekSeeder.php` | Modify | Add `'created_via' => 'admin_calendar'` to booking inserts |
| `tests/Feature/Api/V1/BookingSourceTrackingTest.php` | Create | Feature tests for scenarios S1–S11 |

## Interfaces / Contracts

```php
// New enum
enum BookingSource: string
{
    case AdminCalendar = 'admin_calendar';
    case Agent = 'agent';
    case OnlineWebhook = 'online_webhook';
}

// Changed method — BookingService
public function findOrCreateBooking(
    array $data,
    ?BookingSource $createdVia = BookingSource::OnlineWebhook // new param
): Booking;

// Role detection helper (inline in controller)
private function resolveBookingSource(User $user): BookingSource
{
    return match ($user->role) {
        UserRole::AGENT => BookingSource::Agent,
        default => BookingSource::AdminCalendar, // admin, provider, etc.
    };
}
```

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Unit | `BookingSource` enum values and string backing | Enum value assertions |
| Unit | `UserRole::AGENT->tokenAbilities()` includes `bookings:write` | Direct assertion on the enum method |
| Feature | Admin creates booking → `created_via` = `admin_calendar` | Authenticated POST, assert DB + JSON response |
| Feature | Agent creates booking → `created_via` = `agent` | Same pattern with agent role |
| Feature | Webhook `order.completed` → `created_via` = `online_webhook` | Extend `WebhookOrderCompletedTest` |
| Feature | Webhook replay does NOT change source | Assert source remains unchanged on idempotent replay |
| Feature | Admin/agent updates → `last_modified_via` set | Authenticated PATCH, assert |
| Feature | Admin/agent cancels → `last_modified_via` set | Authenticated PATCH cancel, assert |
| Feature | Webhook refund → `last_modified_via` = `online_webhook` | Extend existing webhook test coverage |
| Feature | GET response exposes `created_via` + conditional `last_modified_via` | Assert resource JSON structure |
| Feature | Agent cancel with token + role middleware passes | Authenticated as agent with `bookings:write` scope |
| Feature | Old records have null source | Assert `created_via` null for pre-migration data |

## Migration / Rollout

**Migration**: `php artisan make:migration add_created_via_and_last_modified_via_to_bookings_table`

```php
Schema::table('bookings', function (Blueprint $table) {
    $table->string('created_via', 40)->nullable()->after('wc_order_id');
    $table->string('last_modified_via', 40)->nullable()->after('created_via');
});
```

**Rollback**:
```php
Schema::table('bookings', function (Blueprint $table) {
    $table->dropColumn(['created_via', 'last_modified_via']);
});
```

**No data migration required** — null = pre-existing records. Frontend handles null gracefully.

## Open Questions

None. The change is self-contained with clear mappings. Provider role maps to `admin_calendar` as they use the same calendar UI — this is an implicit decision worth confirming during review if provider behavior is unexpected.
