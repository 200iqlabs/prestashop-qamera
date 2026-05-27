# Implementation tasks — add-ps-kernel-integration-tests

## 1. Branch + production refactor (`InMemoryDedupCache`)

- [ ] 1.1. Branch `add-ps-kernel-integration-tests` off latest `main`
- [ ] 1.2. Create `src/Sync/InMemoryDedupCache.php` — `final class` with `seen(string $key): bool` (returns true if previously marked, false otherwise — single call atomically checks-and-marks) and an internal `array<string, true> $store` property. PSR-12, strict types, no constructor args.
- [ ] 1.3. Test first: `tests/Unit/Sync/InMemoryDedupCacheTest.php` — `testFirstSeenReturnsFalseAndSubsequentReturnsTrue`, `testDistinctKeysAreIndependent`
- [ ] 1.4. Refactor `ProductImageSyncService` constructor to inject `InMemoryDedupCache $dedupCache` as a new dependency (append to end of constructor for signature stability); replace the internal `private array $seen = []` + `isset($this->seen[$key])` logic with `$this->dedupCache->seen($key)`. The behavioral contract from `product-image-sync` spec is unchanged
- [ ] 1.5. Update `tests/Unit/Sync/ProductImageSyncServiceTest.php` to inject a fresh `InMemoryDedupCache` per test (still allows unit-tier tests to assert dedup behavior — they get a real cache instance, which is fine because the cache itself is pure userland with no PS deps)
- [ ] 1.6. Register `QameraAi\Module\Sync\InMemoryDedupCache` as a public service in `config/services.yml`; add `$dedupCache: '@QameraAi\Module\Sync\InMemoryDedupCache'` to the `ProductImageSyncService` service args
- [ ] 1.7. Verify locally: `docker compose exec prestashop bash -c 'cd /var/www/html/modules/qameraai && vendor/bin/phpunit --testsuite=unit'` — all green, no regressions

## 2. Bootstrap (`tests/Integration/bootstrap.php`)

- [ ] 2.1. Test-first is awkward for a bootstrap (it IS the test infrastructure); instead drive the design from the contract — for each acceptance ("kernel booted", "Db real", "Configuration overridden to invalid URL", "TEST- sweep ran") add an assertion in a sentinel test (§4.1 below) that fails loudly if the bootstrap doesn't deliver
- [ ] 2.2. Implement `tests/Integration/bootstrap.php`:
  - Guard with `if (defined('QAMERAAI_INTEGRATION_BOOTSTRAPPED')) { return; } define('QAMERAAI_INTEGRATION_BOOTSTRAPPED', true);` — idempotency contract from spec §1
  - `require_once '/var/www/html/config/config.inc.php';`
  - `$kernel = new \AdminKernel('dev', true); $kernel->boot();`
  - Expose container via `SymfonyContainer::setInstance($kernel->getContainer())` (mirror the `smoke/test_connection.php` pattern, including the older-PS reflection fallback)
  - `Shop::setContext(Shop::CONTEXT_SHOP, 1); Context::getContext()->shop = new Shop(1);`
  - Capture and stash the production `QAMERAAI_API_BASE_URL` value (so suite-end teardown can restore), then `Configuration::updateValue('QAMERAAI_API_BASE_URL', 'http://qamera-test.invalid')` — spec §3 contract
  - Invoke `cleanupTestFixtures()` (helper from §3) to sweep `TEST-`-prefixed rows left over from prior runs — spec §4 contract
  - Register a `register_shutdown_function` that restores the captured production URL — covers the "restored on harness teardown" half of spec §3
- [ ] 2.3. Write `tests/Integration/cleanup.php` exposing `cleanupTestFixtures(\Db $db): void` — runs `DELETE`s on `ps_qamera_product_link` (via JOIN on `ps_product.reference LIKE 'TEST-%'`), then `ps_product` (reference prefix), then orphan `ps_image` rows whose product is gone. Idempotent — no rows = no error
- [ ] 2.4. Update `phpunit.xml.dist`:
  - Set `bootstrap="tests/Integration/bootstrap.php"` for the integration suite (PHPUnit 10 supports per-suite bootstrap only via separate config files — alternative: keep `tests/bootstrap.php` as the global, and have it `require_once 'tests/Integration/bootstrap.php'` only when an integration test is being run; or split into `phpunit.xml.dist` (unit/contract) + `phpunit.integration.xml` — pick the cleanest in implementation, document choice in commit message)
  - Add `failOnSkipped="true"` at the root — spec §5 contract
  - Remove the `<groups><exclude><group>integration</group></exclude></groups>` block — integration suite is no longer excluded by default

## 3. Fixture helpers (`tests/Integration/Fixtures/`)

- [ ] 3.1. `tests/Integration/Fixtures/ProductFactory.php` — `createProduct(int $idShop, string $marker, string $name = 'TEST Widget', string $sku = null): Product` — creates a real `Product` via `Product::add()`, returns the instance with `reference = "TEST-{$marker}-{$suffix}"`. Defaults: `id_category_default` = PS_HOME_CATEGORY, price 9.99, active=1
- [ ] 3.2. `tests/Integration/Fixtures/ImageFactory.php` — `attachImage(Product $product, string $sourcePath = null): Image` — adds an Image via `Image::add()`, copies the source file (default: `/var/www/html/img/p/lt.jpg`, a PS-bundled flag image known to exist in the dev container) to the resolved `_PS_PRODUCT_IMG_DIR_` path
- [ ] 3.3. `tests/Integration/Fixtures/BookkeepingFactory.php` — `seedPendingRow(Product $product): void` — inserts a row in `ps_qamera_product_link` with status='pending', deterministic `qamera_product_ref` from `ProductRefBuilder`
- [ ] 3.4. `tests/Integration/IntegrationTestCase.php` — base class extending `PHPUnit\Framework\TestCase` with:
  - `protected string $marker; protected function setUp(): void { $this->marker = bin2hex(random_bytes(4)); }`
  - `protected function tearDown(): void { cleanupTestFixturesByMarker(Db::getInstance(), $this->marker); /* + restore container rebinds + restore Configuration writes */ }`
  - Helper `protected function rebindContainerService(string $id, object $instance): void` that stashes the original and registers restoration in tearDown
  - Helper `protected function setConfigurationOverride(string $key, string $value): void` that does the same for `Configuration::updateValue`
- [ ] 3.5. `cleanupTestFixturesByMarker(\Db $db, string $marker): void` — variant of §2.3 that scopes the DELETE to a single marker (used per-test instead of suite-wide)

## 4. Integration tests (flesh out skeletons + sentinel)

- [ ] 4.1. New `tests/Integration/HarnessSelfTest.php` — sentinel that asserts bootstrap delivered: `testKernelBootedAndContainerExposed`, `testDbReturnsRealInstance`, `testConfigurationOverriddenToInvalidUrl`, `testPsProductImgDirIsDefinedAndExists`, `testSuiteStartedWithNoTestPrefixedRows`. If this test fails, every other integration test is suspect — runs first via alphabetical ordering of the file name
- [ ] 4.2. Replace skeleton in `tests/Integration/Sync/ProductImageSyncIntegrationTest.php`:
  - `testRegistersPendingProductOnFirstImage` — uses factories to create product+image+pending row, rebinds `QameraApiClient` with MockHandler returning a registerImage 201 + presigned response, invokes `syncOnImageAdded(idProduct, idImage)`, asserts row transitions to `registered` with response's `qamera_product_id` populated. **This is the Phase-3 smoke regression for the Db LIMIT 1 bug** — it exercises the real `loadBookkeepingRow` SELECT against real `Db::getRow()` (spec §5 regression scenario 1)
  - `testSubsequentImageOnRegisteredProductSkipsMetadata` — pre-seeded `status='registered'`, MockHandler asserts the request body does NOT contain `product_metadata`
  - `testErrorPathPersistsLastErrorMessage` — MockHandler returns 401, asserts row `status='error'` with `last_error_message` starting `API credentials invalid (HTTP 401)`
- [ ] 4.3. New `tests/Integration/Sync/PrimaryImageResolverIntegrationTest.php`:
  - `testResolvesCoverImageFromRealPsImageClass` — creates product + 2 images via factories, marks one as cover via `Image::deleteCover()`+`->cover=1`, asserts resolver returns cover id. **Phase-3 smoke regression for `_PS_PRODUCT_IMG_DIR_` typo** — exercises `resolveImagePath` indirectly via `filesize()` of the resolved path (spec §5 regression scenario 2)
  - `testFallsBackToHintWhenNoCover` — creates product with image but no cover, asserts resolver returns the hint id
- [ ] 4.4. New `tests/Integration/Api/Internal/IdempotencyKeyGeneratorIntegrationTest.php`:
  - `testGeneratesValidUuidUnderRealAutoload` — asserts return value matches canonical UUID regex
  - `testFallsBackToUuid4WhenUuid7Unavailable` — exercises the `method_exists` branch by mocking out `Uuid::uuid7` via runtime shim (or via a wrapper that hides the method) and asserts a valid UUID still returns. **Phase-3 smoke regression for the autoloader clash fallback** (spec §5 regression scenario 3 — limited scope per design Non-Goals)

## 5. CI workflow (`.github/workflows/ci.yml`)

- [ ] 5.1. Add a new `integration` job to `ci.yml` parallel to `static-analysis`:
  - `runs-on: ubuntu-latest`, single PHP version (8.3, PS9 default)
  - Trigger: `pull_request` (same-repo only — fork PRs are intentionally excluded per design Q3; documented in CONTRIBUTING when that becomes relevant)
  - Steps: checkout → `cp .env.example .env` (if needed) → `docker compose up -d` against parent `qameraai-prestashop/docker-compose.yml` → wait for `qameraai-ps` healthy (curl `http://localhost:8080/admin-dev/index.php` until 200 or 5xx) → `docker compose exec prestashop bash -c 'cd /var/www/html/modules/qameraai && composer install --no-progress && vendor/bin/phpunit --testsuite=integration'`
  - On failure: dump `docker compose logs prestashop` and `docker compose exec mysql mysql -uroot -proot prestashop -e "SELECT * FROM ps_log ORDER BY id_log DESC LIMIT 20"` for triage
- [ ] 5.2. Verify the existing `static-analysis` job's `PHPUnit` step now runs `--testsuite=unit,contract` explicitly (was implicit "all suites"; now we need to exclude integration since it requires the dev container)

## 6. Makefile + README

- [ ] 6.1. Add to parent `qameraai-prestashop/Makefile`:
  - `test-unit:` runs `docker compose exec prestashop bash -c 'cd /var/www/html/modules/qameraai && vendor/bin/phpunit --testsuite=unit'`
  - `test-integration:` same shape, `--testsuite=integration`
  - `test:` runs both sequentially (unit first as faster fail signal)
- [ ] 6.2. Update README "Build from source" / "Development" section with a short "Running tests" subsection — unit (fast, default inner loop), integration (requires `make up`), smoke (operator-only, link to internal runbook; do NOT embed credentials)

## 7. Static analysis + style

- [ ] 7.1. `vendor/bin/phpcs` clean on all new files (PSR-12)
- [ ] 7.2. `vendor/bin/phpstan analyse` level 5 — new `src/Sync/InMemoryDedupCache.php` MUST type-check. New `tests/Integration/*` may need entries in `phpstan.neon`'s exclude list if PS legacy globals confuse the analyzer (mirror the existing `src/Install/*` exclusion pattern only if necessary — prefer adding stubs)

## 8. CI matrix verification

- [ ] 8.1. Push branch, watch CI: `static-analysis` matrix on PHP 8.1/8.2/8.3 (unit+contract) MUST stay green; new `integration` job on 8.3 MUST run the integration suite to pass
- [ ] 8.2. Deliberately introduce a regression for each of the 3 smoke bugs on a scratch commit (one at a time), push, verify the appropriate integration test fails — confirms the regression coverage is real, not theatrical. Revert before merge

## 9. PR + merge

- [ ] 9.1. PR against `main` referencing this OpenSpec change
- [ ] 9.2. Address Copilot + manual review comments
- [ ] 9.3. Merge after green CI + §8 regression verification signed off

## 10. Archive

- [ ] 10.1. `/opsx:archive add-ps-kernel-integration-tests` rolling delta into:
  - `openspec/specs/integration-test-harness/spec.md` (new capability)
- [ ] 10.2. README — no Phase table update needed (this is test infrastructure, orthogonal to the Phase plan)
- [ ] 10.3. CHANGELOG (en/pl/uk) — `[Unreleased]` entry noting the new integration harness + tier discipline contract (no user-facing behavior change → no version bump needed; or bump to 1.2.1 if we're tagging the regression-coverage improvement as a patch release; decide at archive time)
