# Tasks: Booking Source Tracking

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~350 (250 new + 100 modified) |
| 400-line budget risk | Medium |
| Chained PRs recommended | No |
| Suggested split | Single PR |
| Delivery strategy | exception-ok |
| Chain strategy | size-exception |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: size-exception
400-line budget risk: Medium

### Suggested Work Units

| Unit | Goal | Likely PR | Notes |
|------|------|-----------|-------|
| 1 | Full source-tracking feature (enum → migration → model → controllers → resource → routes → seeders → tests) | PR 1 | Single cohesive PR; all changes depend on the enum and migration. |

## Phase 1: Foundation

- [x] 1.1 Create `app/Enums/BookingSource.php` — string-backed enum: `AdminCalendar`, `Agent`, `OnlineWebhook`
- [x] 1.2 Create migration — nullable `varchar(40)` `created_via` + `last_modified_via` after `wc_order_id`
- [x] 1.3 Update `UserRole::AGENT->tokenAbilities()` — add `'bookings:write'`

## Phase 2: Core Implementation

- [x] 2.1 Update `Booking` model — add both fields to `$fillable` + cast to nullable `BookingSource`
- [x] 2.2 Update `BookingService::findOrCreateBooking()` — accept `?BookingSource $createdVia = BookingSource::OnlineWebhook`
- [x] 2.3 Add `resolveBookingSource()` to `BookingController` — maps `UserRole` to `BookingSource`
- [x] 2.4 Update `BookingController::store()` — set both `created_via` + `last_modified_via` from role
- [x] 2.5 Update `BookingController::update()` — set only `last_modified_via`; preserve `created_via`
- [x] 2.6 Update `BookingController::cancel()` — same as 2.5 (set `last_modified_via` per role)
- [x] 2.7 Update `WebhookController::handleOrderCompleted()` — pass `BookingSource::OnlineWebhook` to `findOrCreateBooking()`
- [x] 2.8 Update `WebhookController::handleOrderRefunded()` — set `last_modified_via = 'online_webhook'`
- [x] 2.9 Update `BookingResource::toArray()` — expose `created_via` (always) + `last_modified_via` (when not null)

## Phase 3: Wiring

- [x] 3.1 Update `routes/api.php` — add `->middleware('role:admin,agent')` to cancel route
- [x] 3.2 Update all 4 seeders — set `'created_via' => 'admin_calendar'` on booking inserts

## Phase 4: Tests

- [x] 4.1 Create `BookingSourceTrackingTest.php` — S1 admin creates, S2 agent creates, S8 response format
- [x] 4.2 Add webhook source tests — S3 creation, S4 replay idempotency, S7 refund
- [x] 4.3 Add modification tests — S5 admin updates, S6 agent cancels preserves `created_via`
- [x] 4.4 Add token ability test — S9 agent cancels with `bookings:write` scope
- [x] 4.5 Add null source + seeder tests — S10 null for old records, S11 seeded has `created_via`
