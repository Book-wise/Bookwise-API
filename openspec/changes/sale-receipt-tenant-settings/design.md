# Design: Sale Receipt & Tenant Settings

## Technical Approach

Backend-owned PDF receipt generated on demand by dompdf from a Blade view, fed by sale data plus a nullable-safe business header resolved from the authenticated user's tenant. Tenant profile (name/RUT/logo) managed via admin-only endpoints; logo optimized through GD thumbnailing on the `public` disk. `payment_method` normalized to a string-backed enum across migration backfill and write paths. Sale delete uses SoftDeletes.

## Architecture Decisions

| Decision | Option | Tradeoff | Choice |
|---|---|---|---|
| PDF lib | dompdf vs frontend print | Frontend print rejected: contract requires backend `application/pdf` | `barryvdh/laravel-dompdf` |
| Receipt tenant source | `auth()->user()->tenant` vs fixed singleton | Singleton hardcodes "Kinesilk"; per-user FK is multi-tenant-ready (Q2) | `auth()->user()->tenant` |
| `wc_order_id` re-sync | partial unique index vs generated active-key column | Partial index is Postgres/SQLite-only; **prod `.env` is MySQL (no partial index)**. Generated column is portable across MySQL+SQLite | generated column `wc_order_id_active` |
| Legacy `payment_method` | normalize in place | Non-ASCII `débito`/`crédito` kept as-is; app-layer enforcement only | enum + `PaymentMethod::fromLegacy()` |
| Logo resize | GD vs Imagick | Imagick = new extension; GD confirmed present, sufficient for ≤200px header | native GD, webp→jpeg fallback |

## Data Flow

```
Angular client ──Bearer token──▶ auth:sanctum ──▶ scope:sales:read / role:admin
        │
        ├─ GET /sales/{id}/receipt ──▶ SaleReceiptController ──▶ ReceiptService
        │                                        └──▶ Pdf::loadView('receipts.sale') ──▶ 200 application/pdf
        ├─ POST /sales/{id}/receipt/send ──▶ SaleReceiptController ──▶ ReceiptService ──▶ ReceiptMail(attach) ──▶ 202
        ├─ DELETE /sales/{id} ──▶ SaleController::destroy ──▶ Sale::delete() (soft) ──▶ 204
        └─ GET/PATCH/logo /tenant/settings ──▶ TenantController ──▶ Tenant(user->tenant_id)
                                        └─ logo ──▶ LogoService(GD) ──▶ public disk ──▶ business_logo_url
```

## File Changes

| File | Action | Description |
|---|---|---|
| `app/Enums/PaymentMethod.php` | Create | String-backed enum; cases `efectivo, transferencia, débito, crédito, otro, online`; static `fromLegacy(?string): ?PaymentMethod` (null→null, `tarjeta`→crédito, `credit_card`→online, unknown→otro) |
| `app/Models/Tenant.php` | Create | Fillable `business_name, business_rut, business_logo_url`; `users()` hasMany |
| `app/Models/Sale.php` | Modify | `use SoftDeletes`; cast `payment_method` → `PaymentMethod` |
| `app/Models/SaleTransaction.php` | Modify | cast `payment_method` → `PaymentMethod` |
| `app/Models/User.php` | Modify | `tenant_id` fillable; `tenant()` belongsTo |
| `app/Services/ReceiptService.php` | Create | `generate(Sale, ?Tenant): string` → dompdf binary |
| `app/Services/LogoService.php` | Create | `store(UploadedFile, ?Tenant): string` GD flow |
| `app/Services/SaleService.php` | Modify | `PaymentMethod::fromLegacy(...)` on write (webhook choke point) |
| `app/Http/Controllers/Api/V1/SaleReceiptController.php` | Create | `show()` streams PDF (attachment); `send()` emails or 422 |
| `app/Http/Controllers/Api/V1/TenantController.php` | Create | `show/update/uploadLogo`, resolves `auth()->user()->tenant` |
| `app/Http/Controllers/Api/V1/SaleController.php` | Modify | add `destroy()`; `payment_method` → `Rule::enum(PaymentMethod::class)` in store/update/registerTransaction |
| `app/Http/Requests/V1/TenantSettingsRequest.php` | Create | `business_name` string≤255 nullable; `business_rut` nullable + `ChileanRutRule` |
| `app/Http/Requests/V1/LogoUploadRequest.php` | Create | `logo` required `image`, `mimes:jpeg,png,webp`, `max:2048` |
| `app/Mail/ReceiptMail.php` | Create | Mirrors `PaymentConfirmation`; `Attachment::fromData(fn() => $pdf, 'receipt-{id}.pdf', mime application/pdf)` |
| `resources/views/receipts/sale.blade.php` | Create | Nullable-safe header via `@if`; absolute path or base64 logo |
| `database/migrations/*` | Create | 5 migrations (order below) |
| `database/factories/TenantFactory.php` | Create | For tenant settings tests |
| `routes/api.php` | Modify | New routes (below) |
| `composer.json` | Modify | Add `barryvdh/laravel-dompdf` |
| `tests/Feature/Api/V1/*` | Create | 4 test classes |

## Interfaces / Contracts

```php
enum PaymentMethod: string {
    case EFECTIVO = 'efectivo'; case TRANSFERENCIA = 'transferencia';
    case DEBITO = 'débito'; case CREDITO = 'crédito';
    case OTRO = 'otro'; case ONLINE = 'online';
    public static function fromLegacy(?string $value): ?PaymentMethod; // null-safe
}
```
`ReceiptService::generate(Sale $sale, ?Tenant $tenant): string` — returns PDF bytes. `LogoService::store(UploadedFile $file, ?Tenant $tenant): string` — returns `business_logo_url`. RUT reuses existing `App\Rules\ChileanRutRule` (already validates format + check digit; no new rule needed).

## Testing Strategy

| Layer | What | Approach |
|---|---|---|
| Unit | `PaymentMethod::fromLegacy` mapping (tarjeta/credit_card/unknown/null) | `tests/Unit` |
| Feature | Receipt: 200 PDF + attachment headers, Bearer header auth, incomplete profile, 404 trashed, 401; send: 202 + mail, 422 no email, 404 | `SaleReceiptTest` |
| Feature | Tenant: GET null defaults, PATCH partial/valid-RUT/422 invalid-RUT/over-length, logo 200/invalid-mime/oversize/replace, 403 non-admin, 401 | `TenantSettingsTest` |
| Feature | Delete: 204 + hidden from reads, 404 retry, 403 non-admin, transactions survive, re-sync same wc_order_id | `SaleDeletionTest` |
| Feature | Enum write validation rejects invalid `payment_method` (422) | `PaymentMethodTest` |

Factories: `TenantFactory` (new). Sale/Client created directly via `::create()` (matches `IdempotencyTest`); auth via `User::factory()->create(['role'=>UserRole::ADMIN])->createToken('t',['*'])`. `strict_tdd` → RED first, then GREEN.

## Threat Matrix

N/A — no routing, shell, subprocess, VCS/PR automation, executable-file classification, or process-integration boundary.

## Migration / Rollout

Order (rollback via `migrate:rollback --step=5`, reverse):
1. `create_tenants_table` — id, `business_name`(255 null), `business_rut`(255 null), `business_logo_url`(null), timestamps.
2. `add_tenant_id_to_users_table` — nullable FK `constrained('tenants')->nullOnDelete()`.
3. `add_deleted_at_to_sales_table` — nullable `deleted_at`.
4. `replace_sales_wc_order_id_unique` — drop unique on `wc_order_id`; add nullable generated column `wc_order_id_active = CASE WHEN deleted_at IS NULL THEN wc_order_id ELSE NULL END` + unique on it. Portable (MySQL+SQLite). **Deviates from "partial index" resolution** — see risks.
5. `normalize_legacy_payment_methods` — `tarjeta`→`crédito`, `credit_card`→`online`, remaining non-null non-canonical→`otro`, on both `sales` and `sale_transactions` via `DB::table()`.

## Open Questions / Risks

- **RISK (HIGH): MySQL vs partial index.** Prod `.env` resolves `DB_CONNECTION=mysql` (line 30 last-wins), which has no partial indexes; the resolved "partial unique index `WHERE deleted_at IS NULL`" is SQLite/Postgres-only. Design uses the generated-column equivalent. Confirm the MySQL connection is authoritative and the generated column (vs raw `DB::statement` partial index with driver branch) is acceptable.
- **RISK (MEDIUM): SQLite generated column in ALTER TABLE.** Laravel `storedAs()` on SQLite `ALTER ADD COLUMN` may require raw SQL; verify during apply (tests run SQLite `:memory:`).
- **RISK (MEDIUM): receipt tenant for non-admin.** Receipt GET only needs `sales:read`; providers/agents with `tenant_id = null` get an omitted header (still 200, nullable-safe). If a single shared header is desired for all roles, revisit resolution (pending Q2).
- **RISK (LOW): dompdf logo image.** Ensure Blade passes an absolute filesystem path (or base64) so dompdf loads the `public`-disk file off the local driver.
