## Why

Phase 3's operator smoke caught three production bugs that the existing unit-test suite could not have surfaced, because every PrestaShop dependency it touches is stubbed away:

1. `loadBookkeepingRow` appended `LIMIT 1` to a `Db::getRow()` query — the PS `Db` helper auto-appends `LIMIT 1`, so the executed SQL ended `LIMIT 1 LIMIT 1` and threw `PrestaShopException`. The unit test for the method stubs `Db` and never executes real SQL, so it passed.
2. `resolveImagePath` referenced `_PS_PROD_IMG_DIR_` — the real PS9 constant is `_PS_PRODUCT_IMG_DIR_`. The typo evaluated to an empty string at runtime; the unit test never resolves the constant.
3. `IdempotencyKeyGenerator::generate()` called `Uuid::uuid7()` unconditionally. Other PS modules (`ps_checkout`, `ps_accounts`) bundle older `ramsey/uuid` (<4.7) whose autoloader wins when loaded first, so `Uuid::uuid7` resolves to a class without that method. Unit tests load only the module's own vendor, so the conflict cannot manifest.

All three bug classes share one root: the unit tier replaces PrestaShop's surface with stubs, so any contract drift between the stubs and real PS9 (or between the module's vendor and the host runtime's autoloader) is invisible until smoke. We need a test tier that **boots a real PrestaShop kernel** so these contracts execute against the real thing.

The existing `tests/Integration/Sync/*Test.php` files are skeletons marked `@group integration` and skip-when-not-bootstrapped — they were left as placeholders in Phase 3 precisely because the bootstrap they need does not yet exist.

## What Changes

- Introduce `tests/Integration/bootstrap.php` that boots the PrestaShop 9 Symfony kernel, exposes the container to the legacy `SymfonyContainer` adapter (same pattern as `smoke/test_connection.php` proved out), sets shop context, and resolves `_PS_PRODUCT_IMG_DIR_` and friends. The bootstrap is idempotent and reusable across all integration tests.
- Add a new PHPUnit test suite `integration` in `phpunit.xml.dist`, separate from the existing `unit` suite. The suite runs `tests/Integration/**/*Test.php` and uses the new bootstrap.
- Flesh out the three placeholder integration tests in `tests/Integration/Sync/` to actually exercise `ProductImageSyncService` against a real `Db`, real PS9 constants, and real autoload — with Guzzle still mocked via `MockHandler` so no traffic ever hits `qamera.ai`.
- Add a fixture lifecycle: create-product / add-image / assert-bookkeeping-row / cleanup. Fixtures live in MySQL via the same `Db` the production code uses; teardown rolls each test back via a `DELETE` keyed on a per-test marker (transactions across legacy PS classes are unreliable — we tried in Phase 2).
- Extend the runtime story so the suite executes both locally (`docker compose exec prestashop vendor/bin/phpunit --testsuite=integration`) and in CI (new `integration` job in `.github/workflows/ci.yml` running the same command inside the PS9 service container). Same bootstrap, same fixtures, no environment skew.
- Add a `make test-integration` and `make test-unit` shortcut in the parent `qameraai-prestashop/Makefile` so contributors don't need to remember the docker-exec incantation. The smoke flow (already operator-driven and gitignored) is unchanged.
- Document in `README.md` how to run each tier locally: unit (`vendor/bin/phpunit --testsuite=unit`), integration (`--testsuite=integration`, requires `make up`), and smoke (operator-only, link to internal runbook).
- **Not in scope**: replacing unit tests with integration tests, full functional/browser tests, automating smoke against live `qamera.ai` in CI, php-scoper isolation of `ramsey/uuid` (separate concern — fallback in v1.2.0 is sufficient).

## Capabilities

### New Capabilities

- `integration-test-harness`: kernel-bootstrap contract for PHPUnit, fixture lifecycle convention (per-test marker + teardown delete), Guzzle `MockHandler` injection pattern, the dual local/CI execution contract, and the rule that integration tests MUST hit a real PS kernel and real `Db` (mocks reserved for transport).

### Modified Capabilities

None. Existing specs (`prestashop-module-bootstrap`, `product-sync-bookkeeping`, `product-image-sync`, `qamera-api-client`) describe production behavior; this change adds a test tier alongside them without changing any requirement they make of the production code.

## Impact

- **New files**: `tests/Integration/bootstrap.php`; fixture helpers under `tests/Integration/Fixtures/`; expanded versions of `tests/Integration/Sync/{ProductImageSyncIntegrationTest,...}.php` (currently skeletons).
- **Modified files**: `phpunit.xml.dist` (split into `unit` and `integration` suites); `.github/workflows/ci.yml` (new `integration` matrix job); parent `qameraai-prestashop/Makefile` (new `test-unit` / `test-integration` targets); `README.md` (test-tier docs).
- **CI runtime**: +~2 min per matrix row (PS9 + MySQL startup is the bulk). The integration job runs against a single PHP version (8.3, matching prod PS9 default); the static matrix continues to cover 8.1/8.2/8.3.
- **Local-dev DX**: contributors gain a one-command path (`make test-integration`) for the test tier that catches the bug class smoke just caught. Unit tests stay fast (~30s in container) and remain the inner-loop default.
- **Vendor dependencies**: no new prod deps. `composer.json` dev section may grow with a fixture helper if we find we want one (e.g. `dama/doctrine-test-bundle` is NOT a fit — PS uses legacy `Db`, not Doctrine — so we likely roll our own thin teardown helper).
- **Module behavior**: zero change. This is a test-tier change.
