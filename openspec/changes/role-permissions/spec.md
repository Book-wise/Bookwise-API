# Role Permissions — Specification

## Purpose

Model and serve an editable role→permission matrix from the backend so the frontend can render "what can each role do" and (in a follow-up) let the admin adjust it. The backend becomes the single source of truth for role capability, replacing hardcoded frontend action lists.

---

## Requirements

| # | Area | Requirement |
|---|------|-------------|
| R1 | Permissions table | `permissions` — `id`, `group`, `key` (unique), `label` (Spanish), `sort_order`, timestamps. |
| R2 | Role-permission pivot | `role_permission` — `role_id`, `permission_id`, `tenant_id`, timestamps; unique triple `(role_id, permission_id, tenant_id)`. |
| R3 | Permission model | `App\Models\Permission` — fillable, unique key cast/validation. |
| R4 | Pivot model | `App\Models\RolePermission` — belongsTo role/permission/tenant, `scopeForTenant()`. |
| R5 | Role model | `Role` gains `permissions()` belongsToMany over `role_permission`, scoped by tenant. |
| R6 | Seeder | `RolePermissionSeeder` seeds the default permission set per `BusinessRole` slug; MUST run after `RoleSeeder`. |
| R7 | GET /v1/roles | Each role returns `{ id, name, slug, label, permissions: string[] }`; `permissions` scoped to admin's tenant. Tenantless admin → 409 `onboarding_required`. |
| R8 | GET /v1/roles/permissions | Returns the permission catalog grouped by `group`. Requires auth + admin + `scope:roles:read`. |
| R9 | PATCH /v1/roles/{id}/permissions | Replace semantics within the admin tenant (detach + attach, all-or-nothing). Body `{ permissions: string[] }`. Requires admin + `scope:roles:write`. Tenantless admin → 409. Unknown key → 422. |
| R10 | Replace semantics | Existing `role_permission` rows for `(role, admin.tenant)` are detached; the given set attached. Empty array clears all permissions for the role under that tenant. |
| R11 | Catalogue validation | Each element of `permissions` MUST be a known `permissions.key`. Unknown or duplicate → 422, pivot unchanged. |
| R12 | Uniqueness | No duplicate `(role_id, permission_id, tenant_id)` triple. |
| R13 | Catalog | Permission `key` values use dot notation (e.g. `bookings.view`, `clients.create`); `group` is a stable string.
| R14 | Default baseline | The seeded defaults represent the CURRENT intended capability of each role; edits are explicit overrides, not deletions of the capability concept.

---

## Scenarios

### S1: Admin lists roles with permissions
- GIVEN an authenticated admin with a tenant
- WHEN they GET `/v1/roles`
- THEN each role includes `permissions` (array of keys) scoped to that tenant, and no foreign-tenant keys leak

### S2: Tenantless admin lists roles
- GIVEN an authenticated admin WITHOUT a tenant
- WHEN they GET `/v1/roles`
- THEN response is 409 with `onboarding_required`

### S3: Permission catalog is grouped
- GIVEN the seeded catalog
- WHEN an admin GETs `/v1/roles/permissions`
- THEN the response groups permissions by `group` and each has `key` + Spanish `label`

### S4: Admin replaces a role's permissions
- GIVEN a role with default permissions under a tenant
- WHEN an admin PATCHes `/v1/roles/{id}/permissions` with `{ permissions: ['bookings.view', 'clients.create'] }`
- THEN the role's set under that tenant is exactly those two; previous rows detached; HTTP 200

### S5: Empty array clears permissions
- GIVEN a role with permissions under a tenant
- WHEN an admin PATCHes with `{ permissions: [] }`
- THEN all `role_permission` rows for `(role, tenant)` are removed; HTTP 200

### S6: Unknown permission key rejected
- GIVEN a role
- WHEN an admin PATCHes with `{ permissions: ['bogus'] }`
- THEN HTTP 422 with a validation error on `permissions.0`; pivot unchanged

### S7: Duplicate permission keys rejected
- GIVEN a role
- WHEN an admin PATCHes with `{ permissions: ['bookings.view', 'bookings.view'] }`
- THEN HTTP 422; pivot unchanged

### S8: Non-admin denied
- GIVEN an authenticated non-admin user whose token carries `roles:write`
- WHEN they PATCH `/v1/roles/{id}/permissions`
- THEN HTTP 403 `forbidden`

### S9: Missing scope denied
- GIVEN an admin whose token lacks `roles:write`
- WHEN they PATCH `/v1/roles/{id}/permissions`
- THEN HTTP 403

### S10: Tenant isolation
- GIVEN two tenants each with the same role seeded
- WHEN an admin of tenant A edits a permission set
- THEN tenant B's role set is untouched; each tenant sees only its own rows

### S11: Unauthenticated denied
- GIVEN no bearer token
- WHEN an admin (any) requests `/v1/roles/permissions` or PATCHes permissions
- THEN HTTP 401

---

## Constraints

- `role_permission` is tenant-scoped; the pivot is the tenant source of truth (mirroring `user_role`/`RoleAssignment`)
- Permission `key` values MUST be stable slugs used by the frontend; `label` is display-only in Spanish
- Replace semantics are all-or-nothing inside a transaction
- This change is READ + UPDATE of the matrix; frontend enforcement (route guards / button gating) is out of scope
- Seed defaults are the baseline for "what a role can do today"; the admin may override per tenant
