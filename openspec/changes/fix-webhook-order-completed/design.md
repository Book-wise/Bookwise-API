# Design: Fix Webhook order.completed

## Technical Approach

Refactor `handleOrderCompleted()` to extract slot data from `line_items[0].meta_data` (not root `meta_data`), sync the client by `billing.email`, verify availability, then atomically create Booking + Sale + SaleTransaction. Extract business logic into three new service classes following the existing `WooCommerceCustomerService` pattern. Leverage natural idempotency via `wc_order_id` (no `IdempotencyService` — webhooks are server-to-server, not client-driven).

## Architecture Decisions

### Decision: Service layer extraction

| Option | Tradeoff | Decision |
|--------|----------|----------|
| Keep logic in controller | Simple but untestable, violates SRP | ❌ |
| **Single service per concern** | Testable, follows existing pattern (IdempotencyService, WooCommerceCustomerService) | ✅ |
| One monolithic WebhookService | Fewer files but lower cohesion | ❌ |

**Rationale**: The codebase already uses `WooCommerceCustomerService` for customer sync. Three services (Client, Booking, Sale) map 1:1 to domain concerns and make the controller a thin orchestrator.

### Decision: Idempotency via wc_order_id

| Option | Tradeoff | Decision |
|--------|----------|----------|
| IdempotencyService (Idempotency-Key) | Request-scoped, needs header; webhooks carry none | ❌ |
| **Natural idempotency: DB::unique on wc_order_id** | Works without headers, survives replays | ✅ |

**Rationale**: WooCommerce retries use the same `order.id`. Checking `Booking::where('wc_order_id', $orderId)->exists()` before creation handles replays. No schema changes needed.

### Decision: Availability check outside transaction

**Choice**: Verify availability BEFORE opening the DB write transaction.
**Rationale**: Avoids long-lived transactions. The check reads existing bookings (shared lock-safe). If unavailable, return 409 immediately without any write overhead.

## Data Flow

```ascii
WooCommerce Webhook
    │
    ▼
handle() ── HMAC verify ──► handleOrderCompleted()
    │
    ├─► extractLineItemMeta() ← line_items[0].meta_data
    │     missing billing.email → 400
    │     missing slot meta     → 400
    │
    ├─► ClientService::syncFromWooCommerce(billing)
    │     find/upsert Client by email → Client
    │
    ├─► BookingService::verifyAvailability(locationId, startTime, endTime)
    │     overlapping active booking? → 409
    │     available                 → continue
    │
    ├─► Booking exists with wc_order_id?
    │     yes → 200 (existing booking_id, sale_id)
    │
    ├─► DB::transaction()
    │     ├─ BookingService::findOrCreateBooking(data)
    │     └─ SaleService::createFromBooking(booking, paymentData)
    │
    └─► log processed → 200 {booking_id, sale_id, client_id}
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `app/Services/ClientService.php` | **Create** | `syncFromWooCommerce(array $billing): Client` |
| `app/Services/BookingService.php` | **Create** | `verifyAvailability()`, `findOrCreateBooking()` |
| `app/Services/SaleService.php` | **Create** | `createFromBooking(Booking, array $payment): Sale` |
| `app/Http/Controllers/Api/V1/WebhookController.php` | **Modify** | Refactor `handleOrderCompleted()`, add `extractLineItemMeta()` |

## Interfaces / Contracts

### ClientService

```php
class ClientService
{
    public function syncFromWooCommerce(array $billing): Client;
}
// Finds Client by billing.email, upserts fields: first_name, last_name, phone, address
```

### BookingService

```php
class BookingService
{
    public function verifyAvailability(int $locationId, string $startTime, string $endTime, ?int $excludeBookingId = null): bool;
    public function findOrCreateBooking(array $data): Booking;
}
// findOrCreateBooking: checks wc_order_id idempotency, creates with first non-cancellation status
```

### SaleService

```php
class SaleService
{
    public function createFromBooking(Booking $booking, array $paymentData): Sale;
}
// Creates Sale (booking_id, total, payment_method, paid_at) + SaleTransaction
// Calls $sale->recalculatePaidAmount()
```

### Controller helper

```php
private function extractLineItemMeta(array $data): ?array;
// Returns slot_start, slot_end, location_id, service_id, duration_minutes from line_items[0].meta_data
```

## Testing Strategy

| Layer | What | Approach |
|-------|------|----------|
| Unit | `ClientService::syncFromWooCommerce` | Client by email upsert, field updates |
| Unit | `BookingService::verifyAvailability` | Overlap, no-overlap, exclude-booking edge cases |
| Unit | `BookingService::findOrCreateBooking` | New creation vs existing wc_order_id |
| Unit | `SaleService::createFromBooking` | Sale + Transaction created, paid_amount updated |
| Feature | Full webhook flow (200) | POST to route with valid payload → booking+sale+transaction created |
| Feature | Idempotent replay (200) | Same payload twice → 200, no duplicate records |
| Feature | Slot occupied (409) | Pre-seed overlapping booking → 409 before writes |
| Feature | Missing email (400) | Payload without billing.email → 400 |

## Migration / Rollout

No migration required. All new service files, no schema changes. Rollback: `git revert` on controller changes, delete new service files.

## Open Questions

- [ ] Should `findOrCreateBooking` set `provider_id = null` explicitly for WooCommerce bookings? Proposal says out-of-scope, but the column needs a value.
- [ ] Does Sale need `wc_order_id` on creation, or is the booking relationship sufficient? Proposal includes `wc_order_id` on Sale — confirm.
- [ ] Verify `date_paid` format from WC payload: is it ISO8601 or WC datetime string? Handle parsing accordingly.
