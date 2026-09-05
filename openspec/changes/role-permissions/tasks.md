# Tasks: Role Permissions (editable RBAC)

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~600 (450 new + 150 modified) |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | 2 chained PRs (backend model+seed, backend API+tests) |
| Delivery strategy | auto-chain |
| Chain strategy | feature-branch-chain |

Decision needed before apply: No
Chained PRs recommended: Yes
Chain strategy: feature-branch-chain
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | Likely PR | Notes |
|------|------|-----------|-------|
| 1 | Foundation: migrations + models + seeder | PR 1 | `permissions` + `role_permission` tables, `Permission`/`RolePermission` models, `Role::permissions()`, `RolePermissionSeeder` |
| 2 | API + tests: controllers, request, routes, feature tests | PR 2 | `PermissionController`, `RoleController@index` + `assignPermissions`, `AssignRolePermissionsRequest`, routes, `RolePermissionsTest` |

## Phase 1: Foundation (PR 1)

- [ ] 1.1 Create migration `permissions` table (id, group, key UQ, label, sort_order, timestamps)
- [ ] 1.2 Create migration `role_permission` pivot (role_id FK, permission_id FK, tenant_id FK, UQ triple, timestamps)
- [ ] 1.3 Create `App\Models\Permission` (`$fillable`, unique key)
- [ ] 1.4 Create `App\Models\RolePermission` pivot model (belongsTo role/permission/tenant, `scopeForTenant`)
- [ ] 1.5 Update `App\Models\Role` — add `permissions()` belongsToMany over `role_permission`, `withPivot('tenant_id')`
- [ ] 1.6 Create `RolePermissionSeeder` — default permission set per `BusinessRole`; MUST run after `RoleSeeder` (update `DatabaseSeeder` order)
- [ ] 1.7 Register `RolePermissionSeeder` and verify it seeds a non-empty set per role

## Phase 2: API + Tests (PR 2)

- [ ] 2.1 Create `App\Http\Requests\V1\AssignRolePermissionsRequest` — `permissions` present array, each distinct + in-catalog (`exists:permissions,key`), max bounded
- [ ] 2.2 Create `App\Http\Controllers\Api\V1\PermissionController@index` — `GET /v1/roles/permissions`, admin + `scope:roles:read`; returns catalog grouped by `group`
- [ ] 2.3 Update `RoleController@index` — include `permissions: string[]` per role scoped to admin tenant; tenantless → 409 `onboarding_required`
- [ ] 2.4 Add `RoleController@assignPermissions` — `PATCH /v1/roles/{id}/permissions`, replace semantics (detach + attach) in `DB::transaction`; tenantless → 409; non-admin → 403; unknown/dup → 422
- [ ] 2.5 Update `routes/api.php` — new routes with `scope:roles:read`/`scope:roles:write` + `role:admin`
- [ ] 2.6 Create `tests/Feature/Api/V1/RolePermissionsTest.php` — S1..S11 scenarios (read shape, tenantless 409, catalog grouping, replace, empty clear, unknown/dup 422, non-admin 403, missing scope 403, tenant isolation, 401)
- [ ] 2.7 Run `php artisan test --compact tests/Feature/Api/V1/RolePermissionsTest.php` and the full suite; run `vendor/bin/pint --dirty`

## Notes for the implementer

- Mirror the existing role-assignment code path (`assignRoles` + `RoleAssignment` + `AssignProviderRolesRequest`) for consistency.
- The catalog is extensible — `AssignRolePermissionsRequest` validates against `permissions.key` (DB), NOT a hardcoded list.
- `admin_general` default = full catalog; other roles get focused sets (see `design.md` seed table).
- Frontend consumption (rendering the matrix + editing UI) is a SEPARATE follow-up, not part of this change.
