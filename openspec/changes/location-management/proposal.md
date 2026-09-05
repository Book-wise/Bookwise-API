# Proposal: Location Management

## Intent

Complete location CRUD (missing POST), add Chilean regions/comunas reference data, and implement deactivation preflight for future non-finalized bookings — enabling full location management from the frontend.

## Scope

### In Scope
- `POST /v1/locations` — create with unique name, region_id auto-resolves timezone
- `PATCH /v1/locations/{id}` — activate/deactivate with future-booking preflight check
- Migration: `regions` + `comunas` tables; seed 16 regions + comunas
- `GET /v1/regions` + `GET /v1/regions/{id}/comunas` — public reference endpoints
- Consistent Spanish toast messages for all location responses
- LocationResource: add `opening_time`, `closing_time`

### Out of Scope
- DELETE endpoint (existing, not changing)
- Providers cascade on deactivation
- Historical backfill for region/comuna string columns

## Capabilities

### New Capabilities
- `location-management`: full CRUD + activate/deactivate with preflight booking conflict check
- `regions-reference`: Chilean regions and comunas reference data with timezone mapping

### Modified Capabilities
- None

## Approach

1. **New tables**: `regions` (id, name, timezone, sort_order), `comunas` (id, region_id FK, name)
2. **Location migration**: add `region_id` FK, `comuna_id` FK; keep old `region`/`comuna` strings as deprecated
3. **Models**: `Region`, `Comuna`; update `Location` with new fillable + relations
4. **LocationService**: `resolveTimezone(int $regionId): string`, `checkDeactivationPreflight(int $locationId): array` — query bookings where `start_time > now` AND `status.is_finalized = false`
5. **LocationController::store()**: validate unique name; auto-resolve timezone from region_id; return 201 + "Sucursal creada exitosamente"
6. **LocationController::update()**: if `active: false` (no `force: true`) → preflight check. Conflicts → 409 with `requires_confirmation: true` + affected booking list. No conflicts or force=true → 200 with appropriate message
7. **RegionController**: public GET endpoints for regions and their comunas
8. **Auth**: POST + PATCH → `scope:bookings:write`. Region endpoints → public

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `database/migrations/*_create_regions_comunas_tables.php` | **New** | regions + comunas schema + FK columns on locations |
| `app/Models/Region.php` | **New** | Region model |
| `app/Models/Comuna.php` | **New** | Comuna model |
| `app/Services/LocationService.php` | **New** | Preflight + tz resolution |
| `app/Http/Controllers/Api/V1/RegionController.php` | **New** | Public region endpoints |
| `app/Http/Controllers/Api/V1/LocationController.php` | Modified | Add store(), preflight in update() |
| `app/Models/Location.php` | Modified | Add region_id/comuna_id fillable + relations |
| `app/Http/Resources/V1/LocationResource.php` | Modified | Add opening_time, closing_time |
| `routes/api.php` | Modified | Add POST + region routes |
| `database/seeders/DatabaseSeeder.php` | Modified | Call RegionSeeder + ComunaSeeder |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Old region/comuna string columns grow stale | Low | Keep as deprecated read-only, frontend migrates to IDs |
| Region timezone mapping incorrect | Low | Seed from official source, document mapping |
| `force: true` bypasses preflight — frontend could send force always | Medium | Log each force execution (user, timestamp, affected booking count) for audit trail. Document in API contract that force requires user confirmation |
| Race condition: booking created between preflight (409) and force execution | Medium | Accept as designed — booking created after preflight was not visible at confirmation time. Document as expected behavior in API docs |
| Preflight query logic untested → silent regressions | Medium | Mandatory tests: unit test for `checkDeactivationPreflight()` + feature test for full 409→force flow |

## Rollback Plan

- Rollback last 2 migrations (`php artisan migrate:rollback --step=2`)
- Revert controller/route changes in git
- Old string columns remain fully functional

## Dependencies

- None

## Success Criteria

- [ ] `POST /v1/locations` with region_id creates location with correct timezone, returns 201
- [ ] `POST /v1/locations` with duplicate name returns 422
- [ ] `PATCH { active: false }` with future non-finalized bookings returns 409 + booking detail
- [ ] `PATCH { active: false, force: true }` succeeds despite future bookings
- [ ] `GET /v1/regions` returns 16 regions sorted by sort_order
- [ ] `GET /v1/regions/{id}/comunas` returns only that region's comunas
- [ ] All response messages match the Spanish toast format
