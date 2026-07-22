# Tasks: Webhook Async Processing

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~700-1100 |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | PR 1 (Job + model + new tests) → PR 2 (Controller refactor + existing test update) |
| Delivery strategy | ask-on-risk |
| Chain strategy | pending |

Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: pending
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | Likely PR | Notes |
|------|------|-----------|-------|
| 1 | Job class + model update + new tests | PR 1 | Base branch `main`; all job logic shipped and tested standalone |
| 2 | Controller refactor + existing test update | PR 2 | Base branch `main`; depends on PR 1 being merged; existing tests require `QUEUE_CONNECTION=sync` |

## Phase 1: Foundation

- [x] 1.1 Add `'processing'` to `WoocommerceWebhooksLog` allowed statuses

## Phase 2: Core Implementation

- [x] 2.1 Create `app/Jobs/ProcessWooCommerceWebhook.php` — `ShouldQueue`, `ShouldBeUnique`, event routing, `handle()`, `failed()`, `backoff()`, `uniqueId()`
- [x] 2.2 Refactor `WebhookController::handle()` — strip all business logic; keep HMAC validate → log `received` → dispatch job → return 200

## Phase 3: Testing

- [x] 3.1 Add controller test: valid HMAC returns 200 + dispatches job (`Queue::fake()`)
- [x] 3.2 Add job unit tests: event routing (order.completed, order.refunded, customer.*), backoff shape, unique constraint, failure path
- [x] 3.3 Add job integration test: order.completed creates booking + sale via sync queue
- [x] 3.4 Update `WebhookOrderCompletedTest` to work with async flow (controller uses `Queue::fake()`, business logic tests call `Job::handle()` directly)

## Phase 4: Documentation

- [x] 4.1 Add deployment note: queue worker command for `webhooks` queue
