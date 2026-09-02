# Proposal: Role Permissions (editable RBAC)

## Intent

Roles today are a fixed set of six `BusinessRole` slugs that encode behavior only implicitly (via frontend logic and controller middleware). There is no backend notion of "what a role can do". The admin needs to understand and, in the future, adjust what each role can do (which screens/actions it can access) — and that permission matrix must be **modeled and served by the backend** so the UI can render it and eventually edit it.

This change delivers the **read model** of an editable RBAC system: a permission catalog, a `role_permission` pivot, seed defaults per role, and a read-only + update API. It ends the hardcoded `role-meta`/action lists in the frontend by making the backend the single source of truth for "what can a role do".

## Scope

### In Scope
- New `permissions` table (id, key, group, label) + `Permission` model + seeder (catalog of ~12–16 concrete permissions)
- New `role_permission` pivot (role_id, permission_id, tenant_id) + `RolePermission` pivot model
- Seed default permission sets per each of the 6 `BusinessRole` slugs
- `GET /v1/roles` → include `permissions` (list of keys) per role, tenant-scoped
- `PATCH /v1/roles/{id}/permissions` → replace the permission set for a role within the admin's tenant (replace semantics, mirroring `PATCH /providers/{id}/roles`)
- `PermissionController` (index) for the permission catalog
- Tenant scoping + admin-only guards, matching existing role assignment conventions
- Tests covering catalog, defaults, replace semantics, tenant isolation, auth gates, and tenantless 409

### Out of Scope
- **Per-user overrides** — permissions are role-level only in this change
- **Frontend enforcement** of permissions (route guards / button gating) — this change only exposes the model + update API; the frontend consumption (rendering the matrix and editing it) is a follow-up
- **Dynamic capability middleware** — no new middleware that checks a permission; existing `scope:` and `role:` middleware remain the enforcement layer
- **Multi-tenant permission templates / copies** — defaults come from a fixed seed, not per-tenant custom matrices (tenant-scoped override is future)
- Deleting roles or permissions

## Capabilities

### New Capabilities
- `role-permissions`: backend permission catalog + editable role→permission matrix exposed via API

### Modified Capabilities
- `roles-management` (implicit): `GET /v1/roles` now also returns the permission set per role

## Approach

1. **Migration A** — create `permissions` table: `id`, `group` (enum-ish string, e.g. `bookings|clients|providers|settings|roles`), `key` (unique slug, e.g. `bookings.view`, `bookings.create`), `label` (Spanish, e.g. `Ver turnos`), `sort_order`, timestamps.
2. **Migration B** — create `role_permission` pivot: `role_id` FK → roles, `permission_id` FK → permissions, `tenant_id` FK → tenants, timestamps. Unique triple `(role_id, permission_id, tenant_id)`.
3. **Models** — `Permission` (fillable + unique key), `RolePermission` pivot model (like `RoleAssignment`), `Role` gains a `permissions()` belongsToMany scoped by tenant.
4. **Seeder** — `RolePermissionSeeder` defining the default permission set per `BusinessRole` (single source of truth for what each role can do today). `RoleSeeder` runs first (roles exist); the permission seeder runs after.
5. **`PermissionController::index`** — `GET /v1/roles/permissions` (auth: `scope:roles:read` + admin) returns the catalog grouped by `group`.
6. **`RoleController::index`** — extend the existing roles response so each role includes `permissions: string[]` (keys) scoped to the admin's tenant. Tenantless admin → 409 `onboarding_required` (BR18 parity).
7. **`RoleController::assignPermissions`** — `PATCH /v1/roles/{id}/permissions` with body `{ permissions: ['bookings.view', ...] }`, replace semantics within the admin tenant, mirroring `assignRoles`. Validates each key against `permissions.key` (unknown → 422). Admin-only + `scope:roles:write` + tenantless 409.
8. **Tokens** — no new token abilities required if `roles:read`/`roles:write` already exist (confirm; add if missing).
9. **Frontend contract** — `GET /v1/roles` now returns, per role: `{ id, name, slug, label, permissions: string[] }`. The roles screen will render the matrix from this (frontend consumption is a follow-up).

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `database/migrations/..._create_permissions_table` | **New** | Permission catalog |
| `database/migrations/..._create_role_permission_table` | **New** | Role↔permission pivot |
| `app/Models/Permission.php` | **New** | Permission model |
| `app/Models/RolePermission.php` | **New** | Pivot model |
| `app/Models/Role.php` | Modified | Add `permissions()` belongsToMany |
| `app/Http/Controllers/Api/V1/PermissionController.php` | **New** | Catalog index |
| `app/Http/Controllers/Api/V1/RoleController.php` | Modified | Include permissions + add assignPermissions |
| `app/Http/Requests/V1/AssignRolePermissionsRequest.php` | **New** | Validation (keys enum-like) |
| `database/seeders/RolePermissionSeeder.php` | **New** | Default matrix per role |
| `routes/api.php` | Modified | New permission routes |
| `tests/Feature/Api/V1/RolePermissionsTest.php` | **New** | Coverage |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| The frontend `role-meta`/action lists still hardcode capabilities | Certain (this change) | Follow-up frontend change reads permissions from the API; this change explicitly does NOT enforce them yet |
| Tenant isolation of permission sets | Medium | Pivot carries `tenant_id`; all role queries scope `wherePivot('tenant_id', admin.tenant_id)` |
| Seeder ordering (roles vs permissions) | Low | Permission seeder depends on roles being seeded; run after `RoleSeeder` |
| Replace semantics could remove permissions the frontend still expects | Medium | Default seed is the baseline; admin edits are explicit; add tests asserting the default set |

## Rollback Plan

- Revert migrations: `php artisan migrate:rollback --step=2`
- Revert code changes: revert the commit
- `role_permission` rows are dropped; `permissions` catalog dropped. `GET /v1/roles` reverts to not including `permissions`.
- Seeded data removed on rollback; no destructive impact on user data.

## Dependencies

- Existing `roles` table + `RoleSeeder` (roles must exist before permission seeding)
- Existing `tenants` table (pivot is tenant-scoped)

## Success Criteria

- [ ] `permissions` catalog seeded with grouped, Spanish-labeled permissions
- [ ] Each of the 6 roles has a sensible default permission set seeded
- [ ] `GET /v1/roles` includes `permissions: string[]` per role, tenant-scoped
- [ ] `PATCH /v1/roles/{id}/permissions` replaces the set with replace semantics
- [ ] Unknown permission key → 422; tenantless admin → 409; non-admin → 403; missing scope → 403
- [ ] Two tenants never see each other's permission sets
- [ ] `role_permission` is unique per `(role, permission, tenant)`
