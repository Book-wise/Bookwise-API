# Payment Method Specification

## Purpose

Canonical payment method value set and reconciliation of legacy values stored in the `payment_method` column of `sales` and `sale_transactions`.

## Requirements

### Requirement: PaymentMethod enum

The system MUST define `App\Enums\PaymentMethod` as a string-backed enum with cases `efectivo`, `transferencia`, `débito`, `crédito`, `otro`, `online`. The `débito` value MUST retain its accent.

#### Scenario: Canonical set
- GIVEN the enum
- WHEN its cases are listed
- THEN they are exactly `efectivo`, `transferencia`, `débito`, `crédito`, `otro`, `online`

### Requirement: Normalize legacy values

A migration MUST reconcile legacy `payment_method` values in place: `tarjeta` → `crédito` and `credit_card` → `online`. Values already canonical (`efectivo`, `transferencia`, `débito`, `online`) MUST remain unchanged. `null` MUST remain null.

#### Scenario: tarjeta normalized
- GIVEN a sale with `payment_method = 'tarjeta'`
- WHEN the normalization migration runs
- THEN its value becomes `crédito`

#### Scenario: credit_card normalized
- GIVEN a sale with `payment_method = 'credit_card'`
- WHEN the normalization migration runs
- THEN its value becomes `online`

#### Scenario: Canonical values preserved
- GIVEN a sale with `payment_method = 'débito'`
- WHEN the normalization migration runs
- THEN its value remains `débito`

### Requirement: Cast and validate

The `Sale` and `SaleTransaction` models MUST cast `payment_method` to `PaymentMethod` (nullable). Write endpoints (store, update, transaction) MUST reject values outside the enum with 422.

#### Scenario: Invalid value rejected
- GIVEN a request with a `payment_method` not in the enum
- WHEN a sale or transaction is written
- THEN it returns HTTP 422

#### Scenario: Receipt shows normalized label
- GIVEN a sale whose `payment_method` is a canonical enum value
- WHEN the receipt is generated
- THEN the PDF shows that normalized value

## Constraints

- The column remains a nullable string; the enum is enforced at the application layer, not the DB.
