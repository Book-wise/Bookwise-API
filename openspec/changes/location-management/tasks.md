# Tasks: Location Management

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~450-550 |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | PR 1: DB + Models + Seeders → PR 2: Controllers + Routes + Tests |
| Delivery strategy | ask-on-risk |
| Chain strategy | pending |

```
Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: pending
400-line budget risk: High
```

### Suggested Work Units

| Unit | Goal | Likely PR | Notes |
|------|------|-----------|-------|
| 1 | DB schema + Models + Seeders + Service layer | PR 1 | Foundation — migrations, models, LocationService, RegionController |
| 2 | Controllers + Routes + Tests + Resource | PR 2 | Core logic — LocationController store/update/preflight, tests, messages |

## Phase 1: Foundation — DB + Models + Seeders

- [x] 1.1 Create migration `create_regions_and_comunas_tables` with `regions` (id, name, timezone, sort_order) and `comunas` (id, region_id FK, name)
- [x] 1.2 Create migration `add_region_comuna_fk_to_locations` — add `region_id` FK + `comuna_id` FK to locations, both nullable
- [x] 1.3 Create `app/Models/Region.php` — fillable: name, timezone, sort_order; hasMany comunas
- [x] 1.4 Create `app/Models/Comuna.php` — fillable: name, region_id; belongsTo region
- [x] 1.5 Update `app/Models/Location.php` — add `region_id`, `comuna_id` to fillable; belongsTo region + comuna; keep old region/comuna strings
- [x] 1.6 Create `database/seeders/RegionSeeder.php` — seed 16 Chilean regions with correct timezone + sort_order
- [x] 1.7 Create `database/seeders/ComunaSeeder.php` — seed key comunas for active regions
- [x] 1.8 Update `database/seeders/DatabaseSeeder.php` — call RegionSeeder then ComunaSeeder
- [x] 1.9 Create `app/Services/LocationService.php` with `resolveTimezone(int $regionId): string` and `checkDeactivationPreflight(int $locationId): array`
- [x] 1.10 Create `app/Http/Controllers/Api/V1/RegionController.php` — `index()` returns all regions sorted, `showComunas($id)` returns comunas by region_id

## Phase 2: Core — Controllers + Routes + Tests

- [x] 2.1 Add `store()` to `LocationController` — validate name unique, region_id exists, auto-resolve timezone, return 201 + "Sucursal creada exitosamente"
- [x] 2.2 Enhance `update()` in `LocationController` — if `active` changing to `false` without `force`: run preflight; conflicts → 409 with `requires_confirmation` + `affects.bookings[]`; else update normally
- [x] 2.3 Handle `force: true` in `update()` — bypass preflight, `Log::warning` with user + timestamp + booking count, return 200 with warning message
- [x] 2.4 Update `LocationResource` — expose `opening_time`, `closing_time`, nested `region` (`{id, name, timezone}`), nested `comuna` (`{id, name}`)
- [x] 2.5 Add routes: `POST /v1/locations` (scope:bookings:write), `GET /v1/regions` (public), `GET /v1/regions/{id}/comunas` (public)
- [x] 2.6 Unit test `LocationService@resolveTimezone` — returns America/Santiago for Metropolitana, America/Punta_Arenas for Magallanes, throws for invalid
- [x] 2.7 Unit test `LocationService@checkDeactivationPreflight` — returns empty array when no future bookings, returns booking list when conflicts exist
- [x] 2.8 Feature test `POST /v1/locations` — 201 with valid data, 422 duplicate name, 422 invalid region_id
- [x] 2.9 Feature test `PATCH /v1/locations/{id}` — 200 partial update, 200 activate, 409 deactivate with conflicts, 200 force deactivate
- [x] 2.10 Feature test `GET /v1/regions` — returns 16 regions sorted, includes timezone
- [x] 2.11 Feature test `GET /v1/regions/{id}/comunas` — returns only that region's comunas, empty array if region has none
