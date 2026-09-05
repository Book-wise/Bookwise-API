# Testing Capabilities — Bookwise-API

**Strict TDD Mode**: enabled
**Detected**: 2026-08-20

## Test Runner

- **Command**: `php artisan test --compact` / `vendor/bin/phpunit`
- **Framework**: PHPUnit 12.5.23
- **Config**: `phpunit.xml` (SQLite :memory: for testing)
- **Suites**: Unit (`tests/Unit`), Feature (`tests/Feature`)
- **Status**: 102 passed (311 assertions)

## Test Layers

| Layer       | Available | Tool / Notes                        |
|-------------|-----------|--------------------------------------|
| Unit        | ✅        | PHPUnit — 3 files (`tests/Unit`)    |
| Feature     | ✅        | PHPUnit via Laravel TestResponse — 10 files (`tests/Feature/Api/V1`) |
| Integration | ✅        | Laravel HTTP tests with `refreshDatabase` / `refreshApplication` |
| E2E         | ❌        | Not configured                      |

## Coverage

- **Available**: ❌ Not configured in `phpunit.xml` (no `<coverage>` element)
- **Command**: — (add `phpunit.xml` coverage element to enable)

## Quality Tools

| Tool         | Available | Command                             |
|--------------|-----------|--------------------------------------|
| Linter       | ✅        | `vendor/bin/pint` (Laravel Pint 1.x) |
| Type checker | ❌        | Not configured                       |
| Formatter    | ✅        | `vendor/bin/pint` (same as linter)   |

## Strict TDD Resolution

- `openspec/config.yaml`: `strict_tdd: true` ✅
- `AGENTS.md` strict TDD marker: Not found
- Test runner exists: ✅ → Default: **strict_tdd: true**
