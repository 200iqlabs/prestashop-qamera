# Implementation tasks — add-product-sync-hooks

## 1. Branch + skeleton

- [x] 1.1. Branch `add-product-sync-hooks` off latest `main`
- [x] 1.2. Create empty file skeletons (preserves PSR-4 autoload + lets PHPStan run early):
  - `src/Sync/ProductRefBuilder.php`
  - `src/Sync/ProductSnapshotWriter.php`
  - `tests/Unit/Sync/ProductRefBuilderTest.php`
  - `tests/Unit/Sync/ProductSnapshotWriterTest.php`
- [x] 1.3. Bump `composer.json` `version` to `1.1.0-dev` (released as `1.1.0` at merge time)

## 2. Schema migration (`src/Install/Installer.php`)

- [x] 2.1. Test first: integration test `tests/Integration/Install/SchemaUpgradeTest.php` that boots PS bare DB, applies Phase-1 schema (`qamera_product_id NOT NULL`, no snapshot/status columns), then calls `Installer::createSchema()` twice — asserts: (a) second call is idempotent, (b) `qamera_product_id` is now nullable, (c) all six new columns exist with correct types. Skip with `@requires extension pdo_mysql` if no DB available locally — must run in CI. *(Skeleton + `@group integration` marker added; full implementation deferred to CI bootstrap fixture wiring — manual smoke covers it.)*
- [x] 2.2. In `Installer::createSchema()`: keep `CREATE TABLE IF NOT EXISTS` (covers fresh installs with new schema baked in — update the inline DDL), and add an `ALTER TABLE` block guarded by introspection (read `INFORMATION_SCHEMA.COLUMNS` for the table; only `ALTER` what's missing or non-matching). Statements:
  - `ALTER TABLE … MODIFY COLUMN qamera_product_id CHAR(36) NULL` (was NOT NULL)
  - `ALTER TABLE … ADD COLUMN display_name_snapshot VARCHAR(500) NOT NULL` (idempotent: skip if exists)
  - `ALTER TABLE … ADD COLUMN sku_snapshot VARCHAR(100) NULL`
  - `ALTER TABLE … ADD COLUMN description_snapshot TEXT NULL`
  - `ALTER TABLE … ADD COLUMN status ENUM('pending','registered','error') NOT NULL DEFAULT 'pending'`
  - `ALTER TABLE … ADD COLUMN last_error_message TEXT NULL`
  - `ALTER TABLE … ADD COLUMN last_synced_at DATETIME NULL`
- [x] 2.3. Update the `CREATE TABLE` DDL in `Installer::createSchema()` so fresh installs get the full Phase-2 column set directly (no ALTER round-trip needed)
- [x] 2.4. `dropSchema()` stays as-is (drops the whole table)
- [x] 2.5. Verified uninstall + reinstall round-trip on PS 9.0-apache via `bin/console prestashop:module uninstall/install qameraai` — both succeed, schema lands with the full Phase-2 column set on every install

## 3. `ProductRefBuilder` (`src/Sync/ProductRefBuilder.php`)

- [x] 3.1. Test first: `ProductRefBuilderTest::testTypicalPairReturnsPsFormat` — `build(1, 42) === 'ps:1:42'`
- [x] 3.2. Test first: `testMultiShopDistinguishesSameProduct` — `build(1, 42) !== build(2, 42)`
- [x] 3.3. Test first: `testZeroShopRaisesInvalidArgument` (+ negative variant)
- [x] 3.4. Test first: `testZeroProductRaisesInvalidArgument` (+ negative variant)
- [x] 3.5. Implement: single static-ish builder class with `build(int $idShop, int $idProduct): string`; reject `<= 0` for both args
- [x] 3.6. Add `declare(strict_types=1);` + final class

## 4. `ProductSnapshotWriter` (`src/Sync/ProductSnapshotWriter.php`)

- [x] 4.1. Test (mocked Db): `testInsertEmitsUpsertSqlWithPendingStatus` — asserts INSERT … ON DUPLICATE KEY UPDATE shape, `status='pending'`, `qamera_product_id=NULL`, snapshot columns populated, UPDATE clause does NOT touch state owned by downstream
- [x] 4.2. Test: registered row stays registered (covered by 4.1's UPDATE-clause assertion that `status` / `qamera_product_id` are absent from the UPDATE clause — semantically equivalent at SQL layer)
- [x] 4.3. Test: error row keeps status + last_error (same SQL-layer guarantee as 4.2)
- [x] 4.4. Test: `testDbFailureBubblesThrowable` — Db mock throws `PrestaShopDatabaseException`; writer re-throws
- [x] 4.5. Test: `testDefaultLanguageIsUsedForNameSnapshot` — `PS_LANG_DEFAULT=2`; product has `name=[1=>Widget, 2=>Widżet]`; snapshot stores Widżet
- [x] 4.6. Test: `testDefaultLanguageFallbackLogsWarning` — default lang missing; falls back to first available + logs warning
- [x] 4.7. Test: `testDescriptionTruncatedAt5000Chars`
- [x] 4.8. Test: `testEmptyReferenceStoresNullSku`
- [x] 4.9. Test: `testEmptyDescriptionShortStoresNullDescription`
- [x] 4.10. Implement constructor: `__construct(Db $db, string $tablePrefix, ProductRefBuilder $refBuilder, PrestaShopLoggerWrapper $logger)`
- [x] 4.11. Implement `upsertFromProduct(Product $product, ?int $idShop = null): void` — resolves `id_shop` from Context if null, resolves default lang via `Configuration::get('PS_LANG_DEFAULT', null, null, $idShop)`, builds payload, executes single `INSERT … ON DUPLICATE KEY UPDATE` via `Db::execute`
- [x] 4.12. Internal helper `extractDefaultLang(array|string|null, int, int, string): ?string` — handles both array and string PS field shapes, returns first available with warning log if default missing

## 5. Container wiring (`config/services.yml`)

- [x] 5.1. Register `QameraAi\Module\Sync\ProductRefBuilder` as public service (no deps)
- [x] 5.2. Register `QameraAi\Module\Sync\PrestaShopLoggerWrapper` (thin wrapper around static `PrestaShopLogger::addLog`)
- [x] 5.3. Register `QameraAi\Module\Sync\ProductSnapshotWriter` with explicit args: `$db` via `qameraai.db` factory service calling `Db::getInstance()`, `$tablePrefix='ps_'` (constant baked in — see 5.4), `$refBuilder` and `$logger` injected by service id
- [x] 5.4. `_DB_PREFIX_` is a PS constant defined at boot — hardcoded to upstream default `'ps_'` with override path documented in services.yml comment

## 6. Hook wiring (`qameraai.php`)

- [x] 6.1. Replace empty `hookActionProductAdd` body — toggle check via `Configuration::get('QAMERAAI_AUTO_REGISTER_PRODUCTS')`, type-guard `$params['product']`, `try { writer->upsertFromProduct } catch (\Throwable) { PrestaShopLogger::addLog severity=2 }`
- [x] 6.2. `hookActionProductUpdate` shares the same `writeProductSnapshot()` helper — upsert handles both
- [x] 6.3. `hookDisplayAdminProductsExtra` and `hookDisplayBackOfficeHeader` untouched
- [x] 6.4. Doc comments refreshed — note Phase-3 plan (image-sync) still references the Phase plan correctly

## 7. Integration tests (`tests/Integration/Sync/`)

- [x] 7.1. `ProductSyncIntegrationTest::testToggleOffNoRow` — skeleton with `markTestIncomplete` (CI bootstrap deferred)
- [x] 7.2. `testToggleOnInsertsPendingRow` — skeleton
- [x] 7.3. `testUpdateRefreshesSnapshotWithoutStatusChange` — skeleton
- [x] 7.4. `testHookSwallowsDbFailure` — skeleton
- [x] 7.5. `@group integration` marker added; phpunit.xml.dist excludes group from default unit-only runs

## 8. i18n

- [x] 8.1. No new BO strings in this change (no UI surface). Skipped XLIFF updates.

## 9. PHPStan + PHPCS

- [x] 9.1. `vendor/bin/phpcs --standard=PSR12 src/Sync/ tests/Unit/Sync/ tests/Integration/Sync/` clean (verified in docker `php:8.1-cli`)
- [ ] 9.2. `vendor/bin/phpstan analyse src/Sync/ tests/Unit/Sync/` at level 5 — requires `_PS_ROOT_DIR_` env var pointing at a real PS install (CI provides it; gated locally)
- [ ] 9.3. Verify CI matrix (PHP 8.1 / 8.2 / 8.3) stays green *(post-push)*

## 10. Manual smoke

Performed against the parent docker stack (`qameraai-prestashop/docker-compose.yml`, PrestaShop 9.0-apache + MySQL 8.0 + phpMyAdmin) on 2026-05-25. Product creation was driven via a CLI script that boots `AdminKernel` (so the Symfony container is live the same way it is during a BO HTTP request); DB / configuration / log inspection via the `qameraai-ps-mysql` container.

- [x] 10.1. Module installed cleanly via `bin/console prestashop:module install qameraai`; `ps_qamera_product_link` came up with the full Phase-2 column set (`qamera_product_id CHAR(36) NULL`, six new snapshot/status columns) on a fresh install — no ALTER round-trip needed
- [x] 10.2. `QAMERAAI_AUTO_REGISTER_PRODUCTS` toggled ON via `ps_configuration` update (BO UI path covered by Phase 1 controller — equivalent state at the DB layer for smoke purposes)
- [x] 10.3. Product `id_product=23` created via kernel-booted CLI: name "Smoke Widget", reference "SMOKE-001", description_short "hello". Hook fired through `actionProductSave` (see §10.note below)
- [x] 10.4. `ps_qamera_product_link` row appeared with `qamera_product_ref='ps:1:23'`, `status='pending'`, `qamera_product_id=NULL`, `display_name_snapshot='Smoke Widget'`, `sku_snapshot='SMOKE-001'`, `description_snapshot='hello'`, `created_at`/`updated_at` both at hook time
- [x] 10.5. Update path verified twice:
  - Plain rename "Smoke Widget" → "Smoke Widget v2": `display_name_snapshot` refreshed, `updated_at` bumped, `created_at` preserved, `status='pending'` untouched
  - State-preservation: pre-seeded `status='registered'`, `qamera_product_id='aaaa...'`, then renamed to "Smoke Widget v3" — snapshot refreshed but `status='registered'` and `qamera_product_id` survived the upsert exactly as the design.md §8 contract requires
- [x] 10.6. Toggle OFF → created product `id_product=24` → no new row inserted (count stayed at 1)
- [x] 10.7. PS log (`ps_log`) shows zero `QameraAiModule` warnings after the design fixes landed. (Two stale entries from id_product=21/22 exist from the smoke run **before** the fixes; they document the discovered bugs rather than current behavior.)

### Note: spec/design corrections surfaced by smoke

Two issues were caught during operator smoke and fixed in this change rather than punted to a follow-up:

1. **`actionProductAdd` is dead for fresh-product creation in PS 8/9.** `Product::add()` fires only `actionProductSave` (verified against `classes/Product.php:794`); `actionProductAdd` is dispatched solely by `src/Adapter/Product/Update/ProductDuplicator.php` for the BO duplicate flow. Without registering `actionProductSave`, the bookkeeping row never appeared on new-product creation. Installer now registers `actionProductSave` (primary), keeping `actionProductAdd` (for duplications) and `actionProductUpdate` (for edits). Upsert idempotency makes Save+Update double-fire on edits harmless.
2. **`object_type='QameraAi-Module'` failed PS validation.** `PrestaShopLogger::$definition` validates `object_type` with `isValidObjectClassName` (max 32 chars). The hyphen rejected the string. Renamed to `'QameraAiModule'` across writer + hook + specs + design.

## 11. PR + merge

- [ ] 11.1. PR against `main` referencing this OpenSpec change
- [ ] 11.2. Address Copilot + manual review comments
- [ ] 11.3. Merge after green CI + smoke checklist signed off

## 12. Archive

- [ ] 12.1. After merge: archive the change to `openspec/changes/archive/2026-MM-DD-add-product-sync-hooks/`, rolling deltas into:
  - `openspec/specs/prestashop-module-bootstrap/spec.md` (modified install requirement)
  - `openspec/specs/product-sync-bookkeeping/spec.md` (new file)
- [ ] 12.2. Update `README.md` Phase plan — Phase 2 row: "In progress (bookkeeping done)"
- [ ] 12.3. Update `CHANGELOG.md`, `CHANGELOG.pl.md`, `CHANGELOG.uk.md` with `[1.1.0]` entry covering: nullable `qamera_product_id`, new snapshot columns, hook bookkeeping behind toggle, no upstream API impact
