# Sale Deletion Specification

## Purpose

Soft-delete semantics for sales, preserving the financial audit trail while keeping dependent data intact.

## Requirements

### Requirement: Soft delete sale

`DELETE /api/v1/sales/{sale}` MUST require `scope:sales:read` + `role:admin`. It MUST soft delete the `Sale` (set `deleted_at`, via `SoftDeletes`) and return HTTP 204. A soft-deleted sale MUST be excluded from subsequent reads.

#### Scenario: Admin soft deletes
- GIVEN an admin and an existing sale
- WHEN `DELETE /sales/{sale}` is called
- THEN it returns HTTP 204
- AND the sale is hidden from index/show (404 on re-read)

#### Scenario: Sale not found
- GIVEN an admin
- WHEN deleting a non-existent or already-soft-deleted sale
- THEN it returns HTTP 404

#### Scenario: Non-admin forbidden
- GIVEN a non-admin token
- WHEN `DELETE /sales/{sale}` is called
- THEN it returns HTTP 403

### Requirement: Preserve transactions

Soft deleting a sale MUST NOT delete its `sale_transactions` rows. The `cascadeOnDelete` foreign key MUST NOT be triggered by a soft delete.

#### Scenario: Transactions survive soft delete
- GIVEN a sale with transactions
- WHEN the sale is soft deleted
- THEN the `sale_transactions` rows still exist

### Requirement: Allow re-sync after delete

After a sale is soft deleted, creating a new sale with the same `wc_order_id` MUST NOT violate the `wc_order_id` unique index.

#### Scenario: Re-sync same order
- GIVEN a soft-deleted sale with `wc_order_id` X
- WHEN a new sale with `wc_order_id` X is created
- THEN creation succeeds without a unique-constraint error

## Constraints

- `DELETE` is naturally idempotent: the first call returns 204, a retry returns 404 (already trashed). No Idempotency-Key is required.
