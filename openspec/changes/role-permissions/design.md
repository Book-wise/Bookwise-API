# Design: Role Permissions (editable RBAC)

## Technical Approach

Introduce a two-table permission model: a global `permissions` catalog (grouped, keyed, Spanish-labeled) and a tenant-scoped `role_permission` pivot linking each role to the permissions it grants within a tenant. The backend is the single source of truth: `GET /v1/roles` becomes the read model (each role → `permissions: string[]`), and `PATCH /v1/roles/{id}/permissions` becomes the write model (replace semantics). The seed defines the current intended capability baseline; the API lets the admin adjust it per tenant.

## Architecture Decisions

| Option | Alternatives | Decision |
|--------|-------------|----------|
| **Dedicated `permissions` table + `role_permission` pivot** | Hardcoded permission arrays in code | **Two tables** — a catalog allows grouped, labeled, stable keys and future per-tenant overrides; a pivot mirrors the existing `user_role`/`RoleAssignment` pattern |
| **Tenant-scoped pivot** | Global (non-tenant) role→permission matrix | **Tenant-scoped** — booksystem is multi-tenant; each tenant can adjust its own role capabilities without affecting others (mirrors `user_role.tenant_id`) |
| **Replace semantics on PATCH** | Additive/merge semantics | **Replace** — mirrors the established `PATCH /providers/{id}/roles` contract, keeps the API consistent and avoids merge ambiguity |
| **Admin-only + `scope:roles:write`** | Provider/any-authenticated write | **Admin-only + scope** — role editing is a privileged, admin-owned action (same gate as `assignRoles`) |
| **Frontend enforcement deferred** | Enforce in guards immediately | **Deferred** — this change exposes the model + update API only; route-guard/button enforcement is a follow-up, so the seed is the baseline not a live gate |
| **Key naming: dot notation** | Bare snake_case | **Dot notation** (`bookings.view`) — groups naturally and reads cleanly in the matrix UI |

## Data Flow

```
┌───────────────────────────────────────────────────────────────────┐
│  GET /v1/roles (RoleController@index)                            │
│  admin.tenant_id → scope role_permission by tenant               │
│  → each role: { id, name, slug, label, permissions: string[] }   │
│  tenantless → 409 onboarding_required                            │
└───────────────────────────────────────────────────────────────────┘

┌───────────────────────────────────────────────────────────────────┐
│  GET /v1/roles/permissions (PermissionController@index)          │
│  admin + scope:roles:read → catalog grouped by group              │
│  → [{ group, items: [{ key, label }] }]                          │
└───────────────────────────────────────────────────────────────────┘

┌───────────────────────────────────────────────────────────────────┐
│  PATCH /v1/roles/{id}/permissions (RoleController@assignPerms)   │
│  admin.tenant_id → detach role_permission for (role, tenant)      │
│  → attach given permission keys (validated against catalog)       │
│  → all-or-nothing in DB::transaction                            │
│  tenantless → 409; unknown/dup key → 422; non-admin → 403         │
└───────────────────────────────────────────────────────────────────┘
```

## Schema

```
permissions
  id            bigint PK
  group         string(40)      -- bookings | clients | providers | settings | roles
  key           string(80) UQ   -- e.g. 'bookings.view'
  label         string(160)     -- Spanish display
  sort_order    smallint default 0
  timestamps

role_permission
  id            bigint PK
  role_id       FK -> roles.id
  permission_id FK -> permissions.id
  tenant_id     FK -> tenants.id
  timestamps
  UQ(role_id, permission_id, tenant_id)
```

## Model & Service Notes

- `Permission` model: `$fillable` = `[group, key, label, sort_order]`; `key` is unique.
- `RolePermission` pivot model mirrors `RoleAssignment` (`scopeForTenant`).
- `Role::permissions()` belongsToMany over `role_permission`, `withPivot('tenant_id')`, and callers scope via `wherePivot('tenant_id', $admin->tenant_id)`.
- The role/permission assignment logic lives in `RoleController::assignPermissions()` (spec R9/R10), reusing `Role` + `Permission` models and running inside a transaction.
- `AssignRolePermissionsRequest` validates `permissions.*` against `permissions.key` (distinct, in-catalog), mirroring `AssignProviderRolesRequest` but validating against the DB catalog rather than a fixed enum (the catalog is extensible).

## Seed Defaults (baseline)

| Role | Default permissions (keys) |
|------|---------------------------|
| admin_general | all catalog keys |
| admin_local | bookings.*, clients.*, providers.view, settings.view |
| recepcionista | bookings.view/create/confirm/cancel, clients.view/create |
| recepcionista_readonly | bookings.view, clients.view |
| staff | bookings.view, clients.view |
| staff_readonly | bookings.view, clients.view |

> The exact key list is finalized in `RolePermissionSeeder`; the table above is the intended baseline and is what the frontend matrix will render.

## Testing Strategy

- `RolePermissionsTest` covers: catalog shape (S3), roles-with-permissions read (S1), tenantless 409 (S2/S8), replace semantics (S4), empty clear (S5), unknown/duplicate 422 (S6/S7), non-admin 403 (S8), missing scope 403 (S9), tenant isolation (S10), unauthenticated 401 (S11).
- Seed assertions: each BusinessRole has a non-empty default set; `admin_general` has the full catalog.
