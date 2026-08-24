# Tasks: Sale Receipt & Tenant Settings

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~900–1100 |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | PR 1 → PR 2 → PR 3 → PR 4 |
| Delivery strategy | force-chained |
| Chain strategy | pending |

Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: pending
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| 1 | PaymentMethod enum + normalization | PR 1 | `php artisan test --compact --filter=PaymentMethod` | migration on SQLite `:memory:` | revert normalize migration + enum/casts |
| 2 | Tenant model + settings + logo | PR 2 | `php artisan test --compact tests/Feature/Api/V1/TenantSettingsTest.php` | GD (confirmed present) + public disk | revert tenants migrations + Tenant files |
| 3 | Sale SoftDeletes + delete endpoint | PR 3 | `php artisan test --compact tests/Feature/Api/V1/SaleDeletionTest.php` | generated column on SQLite + MySQL | revert deleted_at/generated-col migrations |
| 4 | Receipt PDF + send email | PR 4 | `php artisan test --compact tests/Feature/Api/V1/SaleReceiptTest.php` | dompdf render + Mail::fake | remove dompdf dep + receipt files |

> Migration timestamps MUST preserve dependency order across PRs: tenants → users.tenant_id → sales.deleted_at → wc_order_id_active → normalize. Normalize is independent of the rest.

## Phase 1: PaymentMethod Enum + Normalization

- [x] 1.1 RED: `tests/Unit/PaymentMethodTest.php` — canonical cases (`efectivo`, `transferencia`, `débito`, `crédito`, `otro`, `online`); `fromLegacy`: null→null, `tarjeta`→`crédito`, `credit_card`→`online`, unknown→`otro`, canonical preserved
- [x] 1.2 Create `app/Enums/PaymentMethod.php` string-backed enum + static `fromLegacy(?string): ?PaymentMethod`
- [x] 1.3 Create migration `normalize_legacy_payment_methods` (DB::table on `sales` + `sale_transactions`)
- [x] 1.4 Cast `payment_method` → `PaymentMethod` (nullable) in `Sale` and `SaleTransaction`
- [x] 1.5 `SaleController` store/update/registerTransaction validation → `Rule::enum(PaymentMethod::class)`; `SaleService::createFromBooking` → `fromLegacy()`
- [x] 1.6 RED: `tests/Feature/Api/V1/PaymentMethodTest.php` — invalid `payment_method` on write returns 422
- [x] 1.7 Run `vendor/bin/pint --dirty`

## Phase 2: Tenant Model + Settings + Logo

- [x] 2.1 RED: `tests/Feature/Api/V1/TenantSettingsTest.php` — GET null defaults 200; PATCH partial/valid-RUT/422 invalid-RUT/422 over-length; logo 200/invalid-mime 422/oversize 422/replace; 403 non-admin; 401
- [x] 2.2 Migrations: `create_tenants_table`, `add_tenant_id_to_users_table` (nullable FK `nullOnDelete`)
- [x] 2.3 Create `Tenant` model + `TenantFactory`; add `tenant_id` fillable + `tenant()` on `User`
- [x] 2.4 Create `TenantSettingsRequest` (string≤255 nullable; RUT via `ChileanRutRule`) and `LogoUploadRequest` (`image`, `mimes:jpeg,png,webp`, `max:2048`)
- [x] 2.5 Create `TenantController` (show/update/uploadLogo) resolving `auth()->user()->tenant`
- [x] 2.6 Create `LogoService` — GD thumbnail ≤200px longest side, aspect preserved, webp/jpeg, `public` disk, replace prior file; GD absent → 501
- [x] 2.7 Add routes `GET/PATCH /tenant/settings` + `POST /tenant/settings/logo` under `role:admin`
- [x] 2.8 Run `vendor/bin/pint --dirty`

## Phase 3: Sale SoftDeletes + Delete Endpoint

- [ ] 3.1 RED: `tests/Feature/Api/V1/SaleDeletionTest.php` — 204 + hidden from reads; 404 retry; 403 non-admin; transactions survive; re-sync same `wc_order_id` succeeds
- [ ] 3.2 Migrations: `add_deleted_at_to_sales_table`; `replace_sales_wc_order_id_unique` (drop unique on `wc_order_id`, add generated `wc_order_id_active` + unique on it)
- [ ] 3.3 Add `SoftDeletes` to `Sale`
- [ ] 3.4 Add `destroy()` to `SaleController`; route `DELETE /sales/{id}` under `scope:sales:read` + `role:admin`
- [ ] 3.5 Run `vendor/bin/pint --dirty`

## Phase 4: Receipt PDF + Send Email

- [ ] 4.1 Add `barryvdh/laravel-dompdf` to `composer.json`
- [ ] 4.2 RED: `tests/Feature/Api/V1/SaleReceiptTest.php` — 200 `application/pdf` + attachment; Bearer header auth; incomplete profile 200; 404 trashed; 401; send 202+mail, 422 no email, 404
- [ ] 4.3 Create `resources/views/receipts/sale.blade.php` — nullable-safe header, absolute-path/base64 logo
- [ ] 4.4 Create `ReceiptService::generate(Sale, ?Tenant): string` (dompdf)
- [ ] 4.5 Create `ReceiptMail` (attach PDF via `Attachment::fromData`)
- [ ] 4.6 Create `SaleReceiptController` (show/send); routes `GET /sales/{id}/receipt` (`scope:sales:read`) + `POST .../send` (`scope:sales:read` + `role:admin`)
- [ ] 4.7 Run `vendor/bin/pint --dirty`

## Phase 5: Verification

- [ ] 5.1 Run full suite `php artisan test --compact`
