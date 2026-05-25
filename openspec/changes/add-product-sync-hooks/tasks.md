# Implementation tasks — add-product-sync-hooks

## 1. Branch + skeleton

- [ ] 1.1. Branch `add-product-sync-hooks` off latest `main`
- [ ] 1.2. Create empty file skeletons (preserves PSR-4 autoload + lets PHPStan run early):
  - `src/Sync/ProductRefBuilder.php`
  - `src/Sync/ProductSnapshotWriter.php`
  - `tests/Unit/Sync/ProductRefBuilderTest.php`
  - `tests/Unit/Sync/ProductSnapshotWriterTest.php`
- [ ] 1.3. Bump `composer.json` `version` to `1.1.0-dev` (released as `1.1.0` at merge time)

## 2. Schema migration (`src/Install/Installer.php`)

- [ ] 2.1. Test first: integration test `tests/Integration/Install/SchemaUpgradeTest.php` that boots PS bare DB, applies Phase-1 schema (`qamera_product_id NOT NULL`, no snapshot/status columns), then calls `Installer::createSchema()` twice — asserts: (a) second call is idempotent, (b) `qamera_product_id` is now nullable, (c) all six new columns exist with correct types. Skip with `@requires extension pdo_mysql` if no DB available locally — must run in CI.
- [ ] 2.2. In `Installer::createSchema()`: keep `CREATE TABLE IF NOT EXISTS` (covers fresh installs with new schema baked in — update the inline DDL), and add an `ALTER TABLE` block guarded by introspection (read `INFORMATION_SCHEMA.COLUMNS` for the table; only `ALTER` what's missing or non-matching). Statements:
  - `ALTER TABLE … MODIFY COLUMN qamera_product_id CHAR(36) NULL` (was NOT NULL)
  - `ALTER TABLE … ADD COLUMN display_name_snapshot VARCHAR(500) NOT NULL` (idempotent: skip if exists)
  - `ALTER TABLE … ADD COLUMN sku_snapshot VARCHAR(100) NULL`
  - `ALTER TABLE … ADD COLUMN description_snapshot TEXT NULL`
  - `ALTER TABLE … ADD COLUMN status ENUM('pending','registered','error') NOT NULL DEFAULT 'pending'`
  - `ALTER TABLE … ADD COLUMN last_error_message TEXT NULL`
  - `ALTER TABLE … ADD COLUMN last_synced_at DATETIME NULL`
- [ ] 2.3. Update the `CREATE TABLE` DDL in `Installer::createSchema()` so fresh installs get the full Phase-2 column set directly (no ALTER round-trip needed)
- [ ] 2.4. `dropSchema()` stays as-is (drops the whole table)
- [ ] 2.5. Verify uninstall + reinstall on a stock PS 9.x via `make uninstall && make install` — both pass

## 3. `ProductRefBuilder` (`src/Sync/ProductRefBuilder.php`)

- [ ] 3.1. Test first: `ProductRefBuilderTest::test_typical_pair_returns_ps_format` — `build(1, 42) === 'ps:1:42'`
- [ ] 3.2. Test first: `test_multi_shop_distinguishes_same_product` — `build(1, 42) !== build(2, 42)`
- [ ] 3.3. Test first: `test_zero_or_negative_shop_raises_invalid_argument`
- [ ] 3.4. Test first: `test_zero_or_negative_product_raises_invalid_argument`
- [ ] 3.5. Implement: single static-ish builder class with `build(int $idShop, int $idProduct): string`; reject `<= 0` for both args
- [ ] 3.6. Add `declare(strict_types=1);` + final class

## 4. `ProductSnapshotWriter` (`src/Sync/ProductSnapshotWriter.php`)

- [ ] 4.1. Test first (unit, mocked Db): `test_insert_new_pending_row_when_no_existing` — given empty table, `upsertFromProduct(Product $p)` issues one INSERT … ON DUPLICATE KEY UPDATE bind with `status='pending'`, `qamera_product_id=NULL`, snapshot from default-lang name/reference/description_short
- [ ] 4.2. Test first: `test_existing_registered_row_keeps_status_and_qamera_id` — pre-seed row with `status='registered'`, `qamera_product_id='abc…'`; after upsert, snapshot refreshes, status + qamera_product_id intact
- [ ] 4.3. Test first: `test_existing_error_row_keeps_status_and_last_error` — pre-seed row with `status='error'`, `last_error_message='…'`; after upsert, snapshot refreshes, error metadata intact
- [ ] 4.4. Test first: `test_db_failure_bubbles_throwable` — Db mock throws `\PrestaShopDatabaseException`; writer re-throws (hook layer catches, not the writer)
- [ ] 4.5. Test first: `test_default_language_is_used` — `Configuration::get('PS_LANG_DEFAULT', null, null, 1)` returns `2` (Polish); product has `name=[1=>'Widget', 2=>'Widżet']`; snapshot stores `'Widżet'`
- [ ] 4.6. Test first: `test_default_language_fallback_when_translation_missing` — product has `name=[1=>'Widget']` only, default lang resolves to `2`; snapshot stores `'Widget'` (first available) AND a warning is logged
- [ ] 4.7. Test first: `test_description_truncated_at_5000_chars`
- [ ] 4.8. Test first: `test_empty_reference_stores_null_sku`
- [ ] 4.9. Test first: `test_empty_description_short_stores_null_description`
- [ ] 4.10. Implement constructor: `__construct(Db $db, string $tablePrefix, ProductRefBuilder $refBuilder, PrestaShopLoggerWrapper $logger)` — wrapper around `PrestaShopLogger::addLog` so we can mock it in tests
- [ ] 4.11. Implement `upsertFromProduct(Product $product, ?int $idShop = null): void` — resolves `id_shop` from Context if null, resolves default lang via `Configuration::get('PS_LANG_DEFAULT', null, null, $idShop)`, builds payload, executes single prepared `INSERT … ON DUPLICATE KEY UPDATE` via `Db::execute($sql)`
- [ ] 4.12. Internal helper `extractDefaultLang(string|array $field, int $idLangDefault): ?string` — handles both array and string PS field shapes, returns first available with warning log if default missing

## 5. Container wiring (`config/services.yml`)

- [ ] 5.1. Register `QameraAi\Module\Sync\ProductRefBuilder` as public service (no deps)
- [ ] 5.2. Register `QameraAi\Module\Sync\PrestaShopLoggerWrapper` (thin wrapper around static `PrestaShopLogger::addLog`)
- [ ] 5.3. Register `QameraAi\Module\Sync\ProductSnapshotWriter` with explicit args: `$db=@=service("Db")` (or factory call to `Db::getInstance()`), `$tablePrefix='%qameraai.db_prefix%'`, `$refBuilder=@…ProductRefBuilder`, `$logger=@…PrestaShopLoggerWrapper`
- [ ] 5.4. If `%qameraai.db_prefix%` isn't yet a container param, define it in `services.yml` `parameters:` block reading `_DB_PREFIX_` constant at compile time (or hardcode `ps_` and document — PS doesn't change prefix at runtime)

## 6. Hook wiring (`qameraai.php`)

- [ ] 6.1. Replace empty `hookActionProductAdd` body with: toggle check (`Configuration::get('QAMERAAI_AUTO_REGISTER_PRODUCTS')` truthy gate) → extract `Product` from `$params['product']` (type-guard) → `try { $this->get(ProductSnapshotWriter::class)->upsertFromProduct($product) } catch (\Throwable $e) { PrestaShopLogger::addLog(…, 2, null, 'QameraAi-Module', (int) $product->id, true) }`
- [ ] 6.2. Replace empty `hookActionProductUpdate` body with the same code path (identical to Add — upsert handles both)
- [ ] 6.3. Leave `hookDisplayAdminProductsExtra` and `hookDisplayBackOfficeHeader` untouched (out of scope)
- [ ] 6.4. Confirm doc comments still reference Phase plan correctly

## 7. Integration tests (`tests/Integration/Sync/`)

- [ ] 7.1. `ProductSyncIntegrationTest::test_toggle_off_no_row` — bootstrap PS test env, `Configuration::updateValue('QAMERAAI_AUTO_REGISTER_PRODUCTS', '0')`, create product via `Product::add()`, assert table empty
- [ ] 7.2. `test_toggle_on_inserts_pending_row` — toggle ON, create product, assert row exists with expected columns
- [ ] 7.3. `test_update_refreshes_snapshot_without_status_change` — toggle ON, seed row with `status='registered'`, update product name, assert snapshot refreshed and status untouched
- [ ] 7.4. `test_hook_swallows_db_failure` — drop the table mid-test (simulating broken state), trigger hook, assert PS `Product::add()` still succeeds and PS log received a warning entry
- [ ] 7.5. Skip the integration suite with `@group integration` so unit-only runs (default) stay fast; CI runs both

## 8. i18n

- [ ] 8.1. No new BO strings in this change (no UI surface). Skip XLIFF updates.

## 9. PHPStan + PHPCS

- [ ] 9.1. `vendor/bin/phpcs --standard=PSR12 src/Sync/ tests/Unit/Sync/ tests/Integration/Sync/` clean
- [ ] 9.2. `vendor/bin/phpstan analyse src/Sync/ tests/Unit/Sync/` at level 5 — clean. (`src/Install` still excluded from level-5 globally; the migration changes there don't change that.)
- [ ] 9.3. Verify CI matrix (PHP 8.1 / 8.2 / 8.3) stays green

## 10. Manual smoke

- [ ] 10.1. `make up` → `make install` on local Docker — module installs cleanly with new schema
- [ ] 10.2. http://localhost:8080/admin-dev → Modules → Qamera AI → configuration → toggle "Automatically register new products" ON → save
- [ ] 10.3. Catalog → New product → fill `Name="Smoke Widget"`, `Reference="SMOKE-001"`, `Short description="hello"` → Save
- [ ] 10.4. phpMyAdmin (http://localhost:8081) → `prestashop` DB → `ps_qamera_product_link` — assert one row exists with `qamera_product_ref='ps:1:<idProduct>'`, `status='pending'`, `qamera_product_id=NULL`, `display_name_snapshot='Smoke Widget'`
- [ ] 10.5. Edit the product, change name to "Smoke Widget v2", save → row's `display_name_snapshot` updated, `updated_at` changed, `status` still `'pending'`
- [ ] 10.6. Toggle OFF, create another product → no new row inserted
- [ ] 10.7. Inspect BO Advanced parameters → Logs — confirm no QameraAi warnings (or only the expected ones from edge cases)

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
