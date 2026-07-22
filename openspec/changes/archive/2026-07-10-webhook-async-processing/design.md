# Design: Webhook Async Processing

## Technical Approach

Move all WooCommerce webhook business logic from `WebhookController` into a dedicated `ProcessWooCommerceWebhook` queued job. The controller handles only HMAC validation, log creation, and job dispatch — returns HTTP 200 in <500ms. The job handles event routing, idempotency, retries with exponential backoff, and log state transitions, all on the `webhooks` queue via the `database` driver.

## Architecture Decisions

| Decision | Choice | Alternatives | Rationale |
|----------|--------|-------------|-----------|
| Queue driver | `database` | Redis, SQS | Already the default for this project; no new infra; `QUEUE_CONNECTION=sync` fallback for dev |
| Job uniqueness | `ShouldBeUnique` per `wc_order_id`/`wc_customer_id` | Manual DB lock | Laravel-native, prevents parallel duplicate processing without custom locking |
| Retry strategy | 5 tries, backoff [3,10,30,60,120] | Fixed retry, unlimited retries | Matches WooCommerce's own retry window (~3min); last failure logs as `failed` for manual review |
| Slot unavailable | Retry (not fail-fast) | Fail-fast with log | Race may resolve (concurrent booking released); all retries exhausted → `failed` log |
| Refund before completed | Graceful no-op | Reject | Legitimate edge case; if no booking exists, nothing to cancel |
| Customer events | Same job, same queue | Separate job class | Same retry/idempotency/logging concerns; keeps routing in one place |

## Data Flow

```
WooCommerce ──POST──→ WebhookController::handle()
                          │
                    HMAC validate ──fail──→ 401
                          │
                    Log `received` ─────────────→ woocommerce_webhooks_log
                          │
                    Dispatch ProcessWooCommerceWebhook ──→ jobs (database queue)
                          │
                    Return 200 { received: true }
```

```
ProcessWooCommerceWebhook::handle()
    │
    ├─ Update log → `processing`
    │
    ├─ Route by event:
    │   ├─ order.completed  → extractMeta → syncClient → idempotency check
    │   │                     → verifySlot → DB::transaction(createBooking + createSale)
    │   │
    │   ├─ order.refunded   → Booking::where('wc_order_id') → cancel if exists
    │   │
    │   ├─ customer.*       → WooCommerceCustomerService::syncCustomer()
    │   │
    │   └─ unknown          → no-op
    │
    ├─ Success → log `processed`
    │
    └─ Failure → retry (up to 5×) → log `failed`
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `app/Jobs/ProcessWooCommerceWebhook.php` | **Create** | New job: routes events, handles idempotency, retries, and log state. Implements `ShouldBeUnique`, `ShouldQueue`. Contains `handle()`, `failed()`, `backoff()` |
| `app/Http/Controllers/Api/V1/WebhookController.php` | **Modify** | Strip `handleCustomerEvent()`, `handleOrderCompleted()`, `handleOrderRefunded()`, `extractLineItemMeta()`, `extractBillingData()`. Keep only HMAC validation → log → dispatch |
| `app/Models/WoocommerceWebhooksLog.php` | **Modify** | Add `'processing'` to allowed status values |

No migration needed — `jobs` table already exists. No `config/queue.php` change needed — job sets `$queue = 'webhooks'` directly.

## Interfaces / Contracts

```php
// ProcessWooCommerceWebhook Job
class ProcessWooCommerceWebhook implements ShouldQueue, ShouldBeUnique
{
    public string $event;       // X-WC-Webhook-Topic header value
    public array $payload;      // Decoded JSON payload
    public int $logId;          // WoocommerceWebhooksLog ID

    public $tries = 5;
    public $queue = 'webhooks';

    public function handle(): void;       // Route by event, process, update log
    public function failed(\Throwable): void;  // Update log to 'failed'
    public function backoff(): array;     // [3, 10, 30, 60, 120]
    public function uniqueId(): string;   // wc_order_id or "customer:{wc_customer_id}"
    public function uniqueFor(): int;     // 60 seconds
}
```

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Unit | `ProcessWooCommerceWebhook` event routing | Mock services, assert correct methods called per event type |
| Unit | Job idempotency | Dispatch with existing wc_order_id → assert no duplicate created |
| Unit | Job failure path | Force exception → assert log becomes `failed` with message |
| Unit | `backoff()` and `tries` | Assert array shape and max attempts |
| Feature | Controller HMAC validation | Assert 401 on bad signature, 200 + job dispatch on valid |
| Feature | Controller dispatch | Assert `ProcessWooCommerceWebhook` pushed to `webhooks` queue |
| Integration | Full order.completed flow | Create booking + sale via job, assert DB state |

## Migration / Rollout

**Rollback plan**: Set `QUEUE_CONNECTION=sync` in production — jobs execute inline, restoring synchronous behavior. Deploy previous controller version to fully revert.

**Migration**: No data migration required. Existing `received`/`processed`/`failed` logs are unaffected — the new `processing` status only applies to new job executions.

**Production infra required**: `php artisan queue:work database --queue=webhooks --tries=5` via supervisor/systemd. Documented — not provisioned in this change.

## Deployment / Operations

### Queue Worker Configuration

The `ProcessWooCommerceWebhook` job runs on the `webhooks` queue. Production environments MUST run a dedicated queue worker:

```bash
php artisan queue:work --queue=webhooks --tries=5
```

When using multiple queues, the worker specification matters — `database` is the connection, `webhooks` is the queue name. The full command:

```bash
php artisan queue:work database --queue=webhooks --tries=5
```

### Supervisor / Systemd Configuration

The queue worker must be managed as a long-running process. Example Supervisor config:

```ini
[program:bookwise-webhooks-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/artisan queue:work database --queue=webhooks --tries=5 --sleep=3
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=forge
numprocs=1
redirect_stderr=true
stdout_logfile=/path/to/storage/logs/worker.log
stopwaitsecs=3600
```

### Development Mode

In development, set `QUEUE_CONNECTION=sync` (default for Laravel) to process jobs inline — no worker needed:

```env
# .env.local or .env
QUEUE_CONNECTION=sync
```

> ⚠️ The `sync` driver executes jobs synchronously within the HTTP request. If the job throws an unhandled exception, the controller response will be a 500 error. Use `QUEUE_CONNECTION=database` for realistic testing with a worker process.

## Open Questions

- None resolved during design. All key decisions confirmed against specs.
