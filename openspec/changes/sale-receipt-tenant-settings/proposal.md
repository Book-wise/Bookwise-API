# Proposal: Sale Receipt & Tenant Settings

## Intent

Admin panel needs to view, email, and delete sale receipts, and manage a business profile (name, RUT, logo) shown on receipts. The receipt must be a backend-owned PDF document (single source of truth for View/Send), never dependent on a complete business profile.

## Scope

### In Scope
- `GET /api/v1/sales/{sale}/receipt` — PDF receipt (`application/pdf`, attachment), header from tenant profile (nullable-safe), sale/client/items/total/payment method
- `POST /api/v1/sales/{sale}/receipt/send` — email the same document; 202 `{"sent": true}` or clear error if client has no email
- `DELETE /api/v1/sales/{sale}` — delete sale (soft delete, following project convention) with dependency cleanup
- `GET /api/v1/tenant/settings` — business profile (`business_name`, `business_rut`, `business_logo_url`, all nullable); defaults `null` (200, never 404)
- `PATCH /api/v1/tenant/settings` — partial update; RUT validates Chilean check digit; strings ≤255
- `POST /api/v1/tenant/settings/logo` — multipart `logo`; generates optimized thumbnail (≤200px, webp/jpeg) via GD, stores on `public` disk, returns `business_logo_url`
- `PaymentMethod` enum + legacy value reconciliation
- Routes, admin-only middleware for tenant settings, tests

### Out of Scope
- Receipt for bookings (sales only)
- Receipt archival/persistence — generated on demand
- Multi-tenant infra beyond a single business profile (pending Q2)
- Email template redesign beyond attaching the receipt

## Capabilities

### New Capabilities
- `sale-receipt`: backend-owned receipt PDF generation + email send
- `tenant-settings`: business profile CRUD + logo upload/optimization
- `payment-method`: canonical payment method value set + legacy normalization
- `sale-deletion`: sale delete semantics (soft delete + dependency handling)

### Modified Capabilities
- None (no sale/tenant spec exists in `openspec/specs/`)

## Key Decisions

| Decision | Recommendation | Tradeoff |
|----------|----------------|-----------|
| PDF library | Add `barryvdh/laravel-dompdf` | **BLOCKING** — new dependency, needs explicit user approval per project rules. No PDF lib exists. Alternative (HTML→frontend print) rejected: contract requires backend `application/pdf`. |
| Logo thumbnail | Native GD (`imagecreatetruecolor`, `imagewebp` confirmed present) | Zero new dependency vs Imagick; lower quality but sufficient for header/chip use. |
| `payment_method` | `App\Enums\PaymentMethod`: `efectivo`, `transferencia`, `débito`, `crédito`, `otro`, `online` (WooCommerce-only). Reconcile `tarjeta`→`crédito`, `credit_card`→`online`. | Column is nullable string; non-ASCII `débito` value stored as-is. |
| Sale delete | Add `SoftDeletes` to `Sale` (migration `deleted_at`) | Matches convention (Booking/Client/Location/Service/Provider). Preserves financial audit trail. `sale_transactions` FK is `cascadeOnDelete` — hard delete cascades; soft delete leaves children intact. |
| Tenant model | New `tenants` table + `Tenant` model; admin resolved via token (no tenant in path) | No tenant concept exists today; app is effectively single-tenant ("Kinesilk"). Singleton vs multi-tenant-ready pending Q2. |

## Approach

1. **Enum**: `PaymentMethod` string-backed; `Sale`/`SaleTransaction` cast nullable. Migration reconciles legacy values in place.
2. **Tenant**: `tenants` table + `Tenant` model + `TenantSettingsController` (or `TenantController`) with `role:admin` middleware. `TenantResource`.
3. **Receipt**: `ReceiptService` (PDF build via dompdf, reusable for view+send), `SaleReceiptController`. `GET` streams binary with `Content-Disposition: attachment`.
4. **Send**: `ReceiptMail` Mailable attaching generated PDF; reuse `Mail::to(...)` pattern.
5. **Logo**: `LogoService` — validate image, GD resize (max 200px, preserve ratio), save webp/jpeg to `public` disk, update `business_logo_url`.
6. **Routes**: add under existing `auth:sanctum` group; receipt read under `scope:sales:read`; `DELETE` and `send` under `scope:sales:read` + `role:admin` (matches existing sales write convention); tenant settings under `role:admin`.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `app/Enums/PaymentMethod.php` | New | Canonical enum |
| `app/Models/Sale.php` | Modified | `SoftDeletes`, `payment_method` cast |
| `app/Models/SaleTransaction.php` | Modified | `payment_method` cast |
| `app/Models/Tenant.php` | New | Tenant model |
| `app/Http/Controllers/Api/V1/SaleReceiptController.php` | New | Receipt view + send |
| `app/Http/Controllers/Api/V1/TenantController.php` | New | Settings GET/PATCH + logo |
| `app/Http/Controllers/Api/V1/SaleController.php` | Modified | `destroy()` |
| `app/Services/ReceiptService.php`, `LogoService.php` | New | PDF + thumbnail logic |
| `app/Mail/ReceiptMail.php` | New | Receipt email |
| `database/migrations/*` | New | `tenants`, `sales.deleted_at`, payment_method normalization |
| `routes/api.php` | Modified | New routes |
| `tests/` | New | Receipt, tenant settings, delete coverage |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| New dependency unapproved | High | Block on Q1 before spec/design |
| `credit_card` legacy mapping ambiguous | Medium | Confirm mapping in Q4 |
| Soft-deleting sale leaves `wc_order_id` unique index | Low | `deleted_at` added; unique index on `wc_order_id` — verify soft-delete doesn't block re-sync |
| GD absent on some prod env | Low | Feature-detect; 501 fallback if missing |
| Non-ASCII `débito` value | Low | Keep accent; validate against enum set |

## Rollback Plan

- Revert migrations: `php artisan migrate:rollback --step=N`
- Revert code + dependency: revert commit, `composer remove barryvdh/laravel-dompdf`
- Legacy `payment_method` values retained pre-migration — reversible

## Dependencies

- User approval for `barryvdh/laravel-dompdf` (BLOCKING)
- GD extension (present)

## Success Criteria

- [ ] `GET /v1/sales/{sale}/receipt` returns valid PDF via Bearer header (no `window.open`)
- [ ] Receipt omits missing business fields without failing
- [ ] `POST /v1/sales/{sale}/receipt/send` emails PDF; errors if client has no email
- [ ] `DELETE /v1/sales/{sale}` soft-deletes, preserves transactions
- [ ] Tenant settings GET returns nulls when unset (200); PATCH validates RUT check digit (422 on bad RUT)
- [ ] Logo upload returns optimized thumbnail URL (≤200px)
- [ ] Non-admin gets 403 on tenant settings

## Open Questions (MUST resolve before spec/design)

1. **Q1 — PDF dependency**: approve `barryvdh/laravel-dompdf` (or alternative) — BLOCKING.
2. **Q2 — Tenancy**: singleton `tenant_settings` (single-row) vs `tenants` table + `tenant_id` FK on users (multi-tenant-ready)?
3. **Q3 — Sale delete**: confirm SoftDeletes over hard delete (recommended: soft).
4. **Q4 — `credit_card` mapping**: `online` vs `crédito`?
5. **Q5 — `débito` accent**: keep accented value in DB (recommended) or ASCII `debito`?
