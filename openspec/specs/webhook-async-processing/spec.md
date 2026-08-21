# Webhook Async Processing Specification

## Purpose

Move all WooCommerce webhook business logic into a Laravel queue job. Controller validates HMAC, logs the event, and dispatches — returns 200 immediately. The `ProcessWooCommerceWebhook` job handles all event types with idempotency, retries, and proper log state transitions.

## Requirements

### Requirement: Controller returns 200 immediately

The controller MUST validate the HMAC signature via `X-WC-Webhook-Signature`, create a log with status `received`, dispatch `ProcessWooCommerceWebhook` to the `webhooks` queue, and return HTTP 200 with `{"received": true}`. The controller MUST NOT perform any business logic beyond HMAC validation, logging, and dispatch.

#### Scenario: Valid webhook
- GIVEN a valid HMAC-signed WooCommerce payload
- WHEN the controller processes the request
- THEN it returns HTTP 200 with `{"received": true}`
- AND a log is created with status `received`
- AND a `ProcessWooCommerceWebhook` job is dispatched

#### Scenario: Invalid HMAC signature
- GIVEN a request with an invalid `X-WC-Webhook-Signature`
- WHEN the controller processes it
- THEN it returns HTTP 401 with `{"error": "unauthorized"}`
- AND no job is dispatched
- AND no webhook log is created

### Requirement: Job routes by event type

The job MUST route by event type and perform the corresponding logic:

| Event | Action |
|-------|--------|
| order.completed | Extract meta → sync client → idempotency check → verify slot → create booking + sale (DB transaction) |
| order.refunded | Cancel booking by `wc_order_id`; graceful no-op if none exists |
| customer.created / customer.updated | `WooCommerceCustomerService::syncCustomer()` |

#### Scenario: Order completed creates booking async
- GIVEN a valid order.completed payload with billing data, line-item meta, and available slot
- WHEN the job processes the event
- THEN client, booking, sale, and transaction are created
- AND log transitions to `processed`

#### Scenario: Refund before completed (no booking yet)
- GIVEN an order.refunded with no booking for that `wc_order_id`
- WHEN the job processes it
- THEN it exits gracefully with no side effects
- AND log transitions to `processed`

#### Scenario: Customer event syncs client
- GIVEN a customer.created or customer.updated payload
- WHEN the job processes it
- THEN the client is synced via `WooCommerceCustomerService`
- AND log transitions to `processed`

### Requirement: Idempotent via ShouldBeUnique

The job MUST implement `ShouldBeUnique` using `wc_order_id` to prevent concurrent duplicate processing, and MUST check for existing bookings before creating.

#### Scenario: Duplicate webhook does not duplicate booking
- GIVEN a booking already exists for `wc_order_id` 12345
- WHEN a duplicate order.completed job runs for the same order
- THEN it exits without creating a new booking or sale
- AND log transitions to `processed`

### Requirement: Retry with backoff

The job MUST retry 5 times with [3, 10, 30, 60, 120] second backoff on transient failures. After all retries are exhausted, the log MUST transition to `failed` with the error message.

#### Scenario: Transient failure exhausts retries
- GIVEN a transient error (e.g., DB connection lost)
- WHEN the job fails
- THEN it is re-queued with backoff delay
- AND after 5 failures the log becomes `failed`

#### Scenario: Slot unavailable triggers retry
- GIVEN an order.completed event where the time slot is occupied
- WHEN the job processes and detects unavailability
- THEN the job retries (the race condition may resolve)
- AND after 5 retries the log becomes `failed`

### Requirement: Log state machine

The webhook log status MUST follow: `received` → `processing` → `processed` | `failed`. The job MUST update to `processing` on start, `processed` on success, and `failed` when all retries are exhausted.

#### Scenario: Queue worker not running
- GIVEN a dispatched job with no running queue worker for `webhooks`
- WHEN the controller responds
- THEN HTTP 200 is returned immediately
- AND the job stays in the `jobs` table
- AND the log remains `received` until the worker picks it up
