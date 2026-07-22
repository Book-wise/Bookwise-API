# Proposal: Booking Source Tracking

## Intent

Frontend needs to distinguish how a booking was created and last modified (admin calendar UI, conversational agent, or WooCommerce webhook) to display proper labels and track booking origin. Currently no source metadata exists on the Booking model.

## Scope

### In Scope
- New `BookingSource` enum (`admin_calendar`, `agent`, `online_webhook`)
- Migration: add `created_via` and `last_modified_via` columns to bookings table
- Set source in `BookingController::store()`, `update()`, `cancel()`, `BookingService::findOrCreateBooking()`, and `WebhookController::handleOrderRefunded()`
- Expose both fields in `BookingResource`
- Add `bookings:write` to `UserRole::AGENT` token abilities
- Seeder updates for visual testing
- Tests for creation/modification via each source

### Out of Scope
- Audit log / status history changes — frontend reads these fields directly
- Historical data backfill — new fields are nullable, null = pre-existing records

## Capabilities

No `openspec/specs/` directory exists yet. This change modifies the existing implicit Booking CRUD capability.

### New Capabilities
- `booking-source-tracking`: origin metadata for bookings

### Modified Capabilities
- None (no existing specs to modify)

## Approach

1. **Create `BookingSource` enum** in `app/Enums/BookingSource.php` — string-backed with cases `AdminCalendar`, `Agent`, `OnlineWebhook`
2. **Migration**: `add_created_via_and_last_modified_via_to_bookings_table` — nullable varchar columns, each 40 chars
3. **Model**: add `created_via` and `last_modified_via` to `$fillable` + cast to `BookingSource` (nullable)
4. **Controller logic**:
   - `store()`: detect source via `$request->user()->role` — admin → `admin_calendar`, agent → `agent`
   - `update()` / `cancel()`: same detection → set `last_modified_via`
   - `WebhookController::handleOrderCompleted()` through `BookingService::findOrCreateBooking()` — pass `online_webhook`
   - `WebhookController::handleOrderRefunded()` — set `last_modified_via = online_webhook`
5. **`BookingService::findOrCreateBooking()`**: accept optional `created_via` param (default `online_webhook`)
6. **Resource**: add `created_via` (always) and `last_modified_via` (when not null) to response
7. **Token abilities**: add `'bookings:write'` to `UserRole::AGENT->tokenAbilities()`
8. **Seeders**: pass `created_via` when creating bookings

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `app/Enums/BookingSource.php` | **New** | Enum with 3 cases |
| `app/Models/Booking.php` | Modified | Add fillable + cast |
| `app/Http/Controllers/Api/V1/BookingController.php` | Modified | Set source on create/update/cancel |
| `app/Services/BookingService.php` | Modified | Accept `created_via` param |
| `app/Http/Controllers/Api/V1/WebhookController.php` | Modified | Set `online_webhook` on create + refund |
| `app/Http/Resources/V1/BookingResource.php` | Modified | Expose new fields |
| `app/Enums/UserRole.php` | Modified | Add `bookings:write` for agent |
| `routes/api.php` | Modified | Add `role:admin,agent` to `PATCH /v1/bookings/{id}/cancel` |
| `database/migrations/...` | **New** | Add columns |
| `database/seeders/*.php` | Modified | Set `created_via` |
| `tests/` | **New** | Source-tracking coverage |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Agent can cancel bookings via scope escalation | **Mitigated** | Added `role:admin,agent` to `PATCH /v1/bookings/{id}/cancel` route |
| Nullable fields covered by SoftDeletes migration pattern | Low | Verify existing deletes still cascade |
| Backfill for existing records | None | Null = pre-existing, frontend handles gracefully |

## Rollback Plan

- Revert migration: `php artisan migrate:rollback --step=1`
- Revert code changes: revert the commit
- Existing data retains columns but frontend stops reading them

## Dependencies

- None

## Success Criteria

- [ ] `POST /v1/bookings` as admin sets `created_via = admin_calendar`
- [ ] `POST /v1/bookings` as agent sets `created_via = agent`
- [ ] WooCommerce webhook creates booking with `created_via = online_webhook`
- [ ] `PATCH /v1/bookings/{id}` sets `last_modified_via` per role
- [ ] `PATCH /v1/bookings/{id}/cancel` sets `last_modified_via` per role
- [ ] Webhook refund sets `last_modified_via = online_webhook`
- [ ] Frontend receives both fields in GET responses
- [ ] Agent token has `bookings:write` ability
