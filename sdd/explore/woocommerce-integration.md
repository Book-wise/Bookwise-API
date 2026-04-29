# Exploration: WooCommerce Integration Analysis

## Current State

### What Exists

| Component | File | Purpose |
|-----------|------|---------|
| WooCommerceService | `app/Services/WooCommerceService.php` | API client for WC REST API (products, orders) |
| WebhookController | `app/Http/Controllers/Api/V1/WebhookController.php` | Receives WC webhooks |
| WoocommerceWebhooksLog | `app/Models/WoocommerceWebhooksLog.php` | Log table |

### Data Flow (As-Is)

```
WooCommerce Order (completed)
  ↓ X-WC-Webhook-Topic: order.completed
  ↓ X-WC-Webhook-Signature (HMAC validation)
  ↓ Meta: _kinesilk_booking_id OR lookup by wc_order_id
  → Booking.update(wc_order_id, status=confirmed)
  → Sale.create(wc_order_id)
```

**NO customer sync exists.** The `wc_customer_id` field on Client model is manual entry only.

### Key Fields Across Models

| Model | WC Field | Usage |
|-------|---------|-------|
| Service | `wc_product_id` | Manual mapping |
| Client | `wc_customer_id` | Manual entry only |
| Booking | `wc_order_id` | Set via webhook |
| ClientPack | `wc_order_id` | Manual entry |
| Sale | `wc_order_id` | Set via webhook |

### What's NOT Implemented

1. **No automatic customer sync from WooCommerce** - no webhook for `customer.created`
2. **No WordPress integration** beyond REST API webhooks
3. **No cron jobs** for bulk sync
4. **No lookups** by `wc_customer_id` → Client

### Auth Mechanism

- Webhooks: HMAC-SHA256 signature (no token)
- API: Laravel Sanctum Bearer tokens with scopes
- No separate auth for WC-sourced users - they use same system

## Affected Areas

- `app/Services/WooCommerceService.php` — needs customer sync method
- `app/Http/Controllers/Api/V1/WebhookController.php` — needs `customer.created` handler
- `app/Http/Controllers/Api/V1/ClientController.php` — needs lookup by wc_customer_id
- `app/Models/Client.php` — wc_customer_id already exists
- `app/Http/Controllers/Api/V1/ClientPackController.php` — may need auto-creation from WC order

## Approaches

### 1. Webhook-Based Customer Sync
Add `customer.created` and `customer.updated` webhook handlers.

- **Pros**: Real-time, matches existing pattern
- **Cons**: Requires WC webhook setup per site
- **Effort**: Low

### 2. On-Demand Lookup
When creating a Booking/Sale, lookup Client by `wc_customer_id` if present.

- **Pros**: No WC changes needed
- **Cons**: Client might not exist yet
- **Effort**: Low

### 3. Cron Job Sync
Scheduled task to sync customers from WooCommerce API.

- **Pros**: Bulk sync capability
- **Cons**: More complex, needs error handling
- **Effort**: Medium

### 4. Service Product ID Auto-Mapping
Use `wc_product_id` to auto-create Bookings from WC orders.

- **Pros**: Full automation
- **Cons**: Requires knowing which product → service
- **Effort**: Medium

## Recommendation

**Start with webhook-based sync** - add `customer.created` handler in WebhookController that:
1. Looks up Client by `wc_customer_id`
2. Creates if not exists using WC customer data
3. Links existing Client if found

This is the simplest first step and follows existing patterns.

## Risks

- WC customer data may be incomplete (email optional in WC)
- No source of truth - what if client updates in WC vs API?
- Need to decide: who can update client data?

## Ready for Proposal

**Yes** - user can proceed to create a change proposal for customer sync from WooCommerce.