# Proposal: Webhook Async Processing

## Intent

Controller processes everything synchronously (customer sync, booking, sale creation). WooCommerce times out, retries up to 5×, then disables the webhook. Move all business logic to a queue — controller returns 200 immediately after HMAC validation.

## Scope

### In Scope
- New `ProcessWooCommerceWebhook` job for all events (order.completed, order.refunded, customer.*)
- Strip business logic from controller — keep only HMAC validation, logging, and job dispatch
- Idempotency check moves into the job
- `webhooks` queue on `database` driver
- 5 retries with backoff [3, 10, 30, 60, 120]
- `ShouldBeUnique` per `wc_order_id` / customer ID
- Updated tests (assert job dispatched + new job unit tests)

### Out of Scope
- Monitoring/alerting for failed jobs
- Non-WooCommerce webhooks
- Worker infra provisioning (documented as requirement)

## Assumptions (from user confirmation)

*All events* are async (customer + order). Refund before completed: job finds no booking → no-op, log processed.
Slot unavailable: retry (race may resolve). Last failure: log = `failed`, manual review.

## Capabilities

### New Capabilities
- `webhook-async-processing`: Queued WooCommerce webhook processing — immediate HTTP response, deferred business logic with configurable retries and idempotency

### Modified Capabilities
- None

## Approach

| Step | Responsibility | Detail |
|------|---------------|--------|
| 1 | Controller | Validate HMAC → log `received` → dispatch job → return 200 |
| 2 | Job `handle()` | Check idempotency → route by event type → process → update log |
| 3 | Customer events | `WooCommerceCustomerService@syncCustomer` |
| 4 | Order completed | Client sync → availability check → booking+sale in DB transaction |
| 5 | Order refunded | Cancel booking where `wc_order_id` matches; no-op if none |
| 6 | Failure | Retry up to 5×; `failed()` sets log status = `failed` |

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `app/Http/Controllers/Api/V1/WebhookController.php` | Modified | Strip business logic, keep HMAC + dispatch |
| `app/Jobs/ProcessWooCommerceWebhook.php` | New | All webhook business logic |
| `config/queue.php` | Modified | Add `webhooks` queue to `database` connection |
| Tests | Modified + New | Update existing tests, add job test |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| No queue worker running in production | High | Document infra requirement; `QUEUE_CONNECTION=sync` fallback |
| Refund processed before completed | Low | Graceful no-op — no booking exists yet |
| All retries exhausted | Medium | `failed()` updates log for manual review |

## Rollback Plan

1. Set `QUEUE_CONNECTION=sync` — jobs execute inline (equivalent to current behavior)
2. Deploy previous controller version
3. Remove `webhooks` queue from `config/queue.php`

## Dependencies

- Queue worker running in production: `php artisan queue:work database --queue=webhooks`
- `jobs` table migration published (Laravel default)

## Success Criteria

- [ ] Controller returns 200 in <500ms for all webhook events
- [ ] All existing webhook events processed correctly via job
- [ ] `ShouldBeUnique` prevents duplicate jobs for same `wc_order_id`
- [ ] After 5 failures, log status = `failed`
- [ ] Existing tests pass (updated for async expectations)
