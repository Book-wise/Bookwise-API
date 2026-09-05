## Exploration: Agent authentication & role middleware in Bookwise-API

### Current State

The app uses **Sanctum token-based auth** with two middleware layers layered in routes:

1. **Auth guard**: `auth:sanctum` — validates the Bearer token
2. **Scope middleware** (`scope:` alias → `CheckTokenScope`) — checks the token's abilities
3. **Role middleware** (`role:` alias → `CheckUserRole`) — checks the User's `role` column against allowed values

**Token abilities are assigned at login** in `AuthController::login()`:
```php
$token = $user->createToken('api', $user->role->tokenAbilities())->plainTextToken;
```

The `UserRole::tokenAbilities()` method defines what each role can do. Admin gets `['*']` (wildcard), others get explicit scope lists.

### The Agent Role

The `AGENT` case was added via migration `2026_06_10_000001_add_agent_role_to_users_table.php`. Its current `tokenAbilities()` are:

```php
self::AGENT => ['bookings:read', 'clients:read', 'clients:write', 'providers:read'],
```

**Critically, the AGENT role does NOT have `bookings:write` scope.** This means:
- An agent CANNOT currently access `POST /v1/bookings` (requires `scope:bookings:write`)
- An agent CANNOT access `PATCH /v1/bookings/{id}/cancel` (requires `scope:bookings:write`)
- An agent CAN access `GET /v1/bookings` and `GET /v1/bookings/{id}` (requires `scope:bookings:read`)

### AgentController

Only has one method: `checkAvailability()` — it's a read-only slot query. It doesn't create anything. The route is `GET /api/v1/agent/check-availability` with `scope:bookings:read`.

There is **no agent booking creation endpoint** currently. No `store()` method, no `POST /agent/bookings` route.

### How the app differentiates agent vs admin

| Mechanism | What it checks | Where it's used |
|-----------|---------------|-----------------|
| `scope:bookings:write` middleware | Token ability `bookings:write` | `POST /v1/bookings`, cancel, blocked-slots, etc. |
| `role:admin` middleware | User's `role` column === 'admin' | `POST /v1/sales`, `PATCH /v1/sales/{id}`, `PATCH /v1/pack-sessions/{id}` |
| `role:provider,admin` middleware | User's `role` in ['provider', 'admin'] | `PATCH /v1/bookings/{id}` |
| `CheckOwnership` middleware | Admin bypasses, provider scoped to own locations | Providers' routes |
| Controller-level role check | `$user->role->value === 'provider'` | `BookingController::index()` filters by provider's locations |

### BookingController auth handling

**`store()` (`POST /v1/bookings`):**
- Route middleware: `auth:sanctum` + `scope:bookings:write`
- **No role check** — any authenticated user with `bookings:write` scope can create a booking
- Gets user via `$request->user()` but only uses it in `index()` for provider filtering
- Does NOT check roles, does NOT set any `created_via` field
- Uses `Booking::create([...$validated, ...])` — spreads validated fields only

**`update()` (`PATCH /v1/bookings/{id}`):**
- Route middleware: `auth:sanctum` + `scope:bookings:write` + `role:provider,admin`
- **Providers and admins only** can update bookings

**`cancel()` (`PATCH /v1/bookings/{id}/cancel`):**
- Route middleware: `auth:sanctum` + `scope:bookings:write`
- **No role check** — any authenticated user with write scope can cancel

### Created_via field

- **Does NOT exist yet** in the database or model
- An exploration document exists at `sdd/explore/booking-created-via-fields/exploration.md` recommending a `BookingSource` enum approach
- Current proposed sources: `API`, `ONLINE_WEBHOOK` only
- No `agent` or `admin_calendar` source has been proposed yet

### Affected Areas

- `app/Enums/UserRole.php` — Agent's `tokenAbilities()` may need `bookings:write` added
- `app/Http/Controllers/Api/V1/AgentController.php` — No booking creation exists yet; would need new endpoint
- `app/Http/Controllers/Api/V1/BookingController.php` — `store()` needs to detect agent vs admin caller and set `created_via` accordingly
- `routes/api.php` — May need new agent routes or new role middleware on booking routes
- `app/Enums/UserRole.php` — Missing `isAgent()` helper on User model
- `app/Models/User.php` — No `isAgent()` method (has `isAdmin()` and `isProvider()`)

### Approaches

1. **Add `bookings:write` scope to agent + new agent booking endpoint**
   - Add `bookings:write` to `UserRole::AGENT->tokenAbilities()`
   - Create `AgentController::storeBooking()` with its own route
   - Set `created_via = 'agent'` in the agent endpoint
   - Pros: Clear separation, agent-specific logic stays in AgentController
   - Cons: Duplication of booking creation logic unless extracted
   - Effort: Medium

2. **Add `bookings:write` scope to agent + role middleware on store()**
   - Add `bookings:write` to agent abilities
   - Change `POST /v1/bookings` middleware from `scope:bookings:write` to `scope:bookings:write` + `role:agent,admin,provider`
   - In `BookingController::store()`, detect role and set `created_via`
   - Pros: Single creation endpoint, less duplication
   - Cons: Agents share the same endpoint as admin
   - Effort: Low

3. **Add `bookings:write` scope + `created_via` detection in controller**
   - Add `bookings:write` to agent abilities
   - No route changes needed (scope already there)
   - In `BookingController::store()`, check `$request->user()->role` and set `created_via` to `'agent'` or `'api'` (admin_calendar) accordingly
   - Pros: Minimal changes, no new routes, no new middleware
   - Cons: Admin and agent share the same endpoint, differentiation is purely backend logic
   - Effort: Low

### Recommendation

**Approach #3** is the most pragmatic if the goal is simply to track the source of booking creation. The `scope:bookings:write` middleware already protects the endpoint; adding `bookings:write` to the agent's token abilities is the key enabler. Once the agent has the scope, `BookingController::store()` can check `$request->user()->role`:

```php
'created_via' => match ($request->user()->role) {
    UserRole::AGENT => BookingSource::AGENT->value,
    UserRole::ADMIN => BookingSource::ADMIN_CALENDAR->value,
    default => BookingSource::API->value,
},
```

### Risks

- **Adding `bookings:write` to agent abilities** is a security decision — agents would have write access to all booking endpoints (including cancel). Consider if this is intended.
- **The `BookingSource` enum doesn't exist yet** — the existing exploration proposes `API` and `ONLINE_WEBHOOK` only. Would need `AGENT` and `ADMIN_CALENDAR` cases added.
- **`cancel()` has no role check** — if agents get `bookings:write`, they could cancel any booking. May need a `role:admin,provider` check added.
- **The `created_via` column doesn't exist** — a migration is needed first.

### Ready for Proposal

Yes. The auth/role architecture is clear. The main decisions to make are:
1. Should agents get `bookings:write` scope?
2. Should agent booking creation go through the existing `POST /v1/bookings` endpoint or a new agent-specific one?
3. What `BookingSource` cases are needed for `created_via`?
