## 1. Schema migration

- [x] 1.1 Extend `Installer::createSchema()` to include the four analysis columns in the `CREATE TABLE ps_qamera_product_link` statement (`analysis_status` ENUM, `analysis_described_count` INT UNSIGNED NULL, `analysis_total_count` INT UNSIGNED NULL, `analysis_refreshed_at` DATETIME NULL)
- [x] 1.2 Extend `Installer::migrateProductLinkSchema()` additions array with the four columns (idempotent `INFORMATION_SCHEMA.COLUMNS` check already in place — no separate method needed; same pattern as `qamera_image_id` added in Phase 4.3)
- [x] 1.3 Add `upgrade/upgrade-1.4.0.php` mirroring upgrade-1.3.0.php pattern (INFORMATION_SCHEMA-guarded ADD COLUMN per missing column)
- [x] 1.4 Bump `QameraAi::$version` from `1.3.0` to `1.4.0` in `qameraai.php`
- [x] 1.5 Extend `tests/Integration/Install/SchemaUpgradeTest.php` with `testAnalysisColumnsConvergeAcrossFreshAndUpgradePaths()` stub (marked incomplete, mirrors the existing fresh+upgrade convergence stub — the integration harness for these is the parent-shell PS bootstrap, not unit-runnable)

## 2. API client DTO extension

- [x] 2.1 Added `$analysisStatus: string` and `$analyzedAt: ?string` to `src/Api/Dto/ProductImageDto.php` constructor (after `$sha256`, before `$createdAt` per upstream order). Exposed `ANALYSIS_STATUSES` constant.
- [x] 2.2 `JsonDecoder` is reflection-based + snake_case-aware → auto-maps `analysis_status` / `analyzed_at` from payload; missing required field already raises `ValidationException::malformedResponse('analysis_status')` via existing decoder path. Unknown enum value validated in `ProductImageDto::__construct()` via new factory `ValidationException::invalidEnumValue($field, $value, $allowed)`.
- [x] 2.3 Updated `tests/Contract/Fixtures/products-detail.fixture.json` with `analysis_status='described'` + `analyzed_at` on the single embedded image; bumped `_commit` to `PR-204@2026-05-28` (placeholder for true merge SHA — to refresh when fetching the upstream commit).
- [x] 2.4 Added 4 new test methods in `tests/Unit/Api/QameraApiClientTest.php`: described+analyzed_at present (extended existing test), pending+null analyzed_at, missing analysis_status throws, unknown enum value throws.

## 3. AnalysisStatusRefresher service

- [x] 3.1 Created `src/Sync/AnalysisStatusRefresher.php` with `refresh(SyncedProductLink $link, bool $force = false): RefreshResult`. Class is non-`final` so tests subclass `now()`/`nowTimestamp()`.
- [x] 3.2 Created `src/Sync/RefreshResult.php` value object (`analysisStatus, describedCount, totalCount, refreshedAt, ?refreshError`).
- [x] 3.3 TTL gate `shouldRefresh()` — 60s for in-flight (`pending`/`processing`/NULL), 3600s for settled (`described`/`error`/`partial`); `force=true` bypasses; NULL `analysisRefreshedAt` always pulls.
- [x] 3.4 Aggregate reduction as `public static AnalysisStatusRefresher::aggregate(array): array` — algorithm per spec, earliest-match-wins. Exposed static so the test matrix runs without the full service.
- [x] 3.5 `getProduct($qameraProductRef)` call + UPDATE writing four columns; identifier is `ref` (always non-NULL on registered links).
- [x] 3.6 Sanitiser mirrors `ProductImageSyncService::mapExceptionToLastError()` conventions; severity-2 PrestaShopLogger write; cached row values returned + `refreshError` populated.
- [x] 3.7 `tests/Unit/Sync/AnalysisStatusRefresherTest.php` — 14 tests: TTL fresh/stale, force bypass, NULL pulls, ServerException + ValidationException preserve cache + sanitise, 8 aggregate scenarios (single described/processing/error, multi described+processing→partial, described+error→partial, all-error, empty→null, all-pending).

## 4. SyncedProductLink + Lookup extensions

- [x] 4.1 Extended `SyncedProductLink` ctor with `?string $analysisStatus, ?int $analysisDescribedCount, ?int $analysisTotalCount, ?string $analysisRefreshedAt` + exposed `ANALYSIS_STATUS_*` constants.
- [x] 4.2 `canGenerate()` requires both `qameraImageId !== null/''` AND `analysisStatus === 'described'` (partial also OK for multi-image forward-compat).
- [x] 4.3 Added `getDisabledHint(): ?string` returning the per-state hint per spec mapping; "Sync this product first" takes precedence over analysis hints when image is absent.
- [x] 4.4 `SyncedProductLinkLookup::listForGrid()` SELECT extended; deduplicated row hydration into `hydrate()` helper.
- [x] 4.5 `SyncedProductLinkLookup::loadByProductIds()` SELECT extended identically; also added `findByIdLink()` for the upcoming status JSON endpoint (preempts section 5).
- [x] 4.6 `tests/Support/FakeSyncedProductLinkLookup.php` got matching `findByIdLink()`; ctor change is backward-compatible (all new fields default to null).
- [x] 4.7 `tests/Unit/Packshot/SyncedProductLinkTest.php` — 9-row data provider covering described/partial/processing/pending/error/NULL × image present/absent/empty.

## 5. BO status JSON endpoint

- [ ] 5.1 Create `src/Controller/Admin/ProductStatusController.php` extending `FrameworkBundleAdminController`
- [ ] 5.2 Route `GET /modules/qameraai/products/{idLink}/status` with `force` query param; register in module routing config alongside the existing `_qameraai_admin_products` route
- [ ] 5.3 Implement: shop scoping, 404 JSON on unknown idLink, `AnalysisStatusRefresher::refresh($link, force: $force)` call, JSON response with `id_link, analysis_status, analysis_described_count, analysis_total_count, analysis_refreshed_at, analyzed_at, generate_enabled, badge_class, badge_label, badge_icon, hint, refresh_error?`
- [ ] 5.4 Add `Cache-Control: private, max-age=5` header
- [ ] 5.5 Integration test: 200 happy path, 200 with refresh_error on upstream failure, 404 unknown idLink, force=1 bypasses TTL

## 6. Products grid controller + template update

- [ ] 6.1 Extend `ProductsGridController::indexAction()` row dict mapping to include `analysis_status`, `analysis_described_count`, `analysis_total_count`, `analysis_refreshed_at`, `analyzed_at` (last one read from API on demand? — actually `analyzed_at` is per-image not aggregate, decide: pull through OR drop from row dict and let only the status endpoint surface it; sub-task: confirm in implementation)
- [ ] 6.2 Extend `ProductsGridController::indexAction()` to compute and pass `disabled_hint` per row from `SyncedProductLink::getDisabledHint()`
- [ ] 6.3 Update bulk-select handler (lives in `GenerateFormController` per current Phase-4.3 wiring) to partition selected ids into `[generatable, unsynced, awaiting_analysis]` and emit the combined flash-info per the qamera-bo-ui spec scenario
- [ ] 6.4 Update `views/templates/admin/products_grid.html.twig` to add the "Analysis" column header + badge cell with `data-analysis-status` and `data-id-link` attributes per the badge mapping table
- [ ] 6.5 Update the Generate button in the template to honor `disabled_hint` (set `disabled` + `title` attribute)
- [ ] 6.6 Add a per-row "Refresh analysis" icon button next to Generate; class `js-qamera-refresh-analysis`; carries `data-id-link`
- [ ] 6.7 Add translations to `translations/Modules.Qameraai.Admin.pl-PL.xlf` and `.en-US.xlf` for: badge labels (Pending/Processing/Ready/Error/Partial), hints (Waiting for image analysis…, Image is being analysed…, Image analysis failed — re-sync product, Awaiting analysis status — refresh), bulk-flash sentences, Refresh button label

## 7. Grid JS poll

- [ ] 7.1 Create `views/js/products_grid.js` (no NPM, no build — vanilla ES5/ES6 + jQuery if needed)
- [ ] 7.2 Implement on `DOMContentLoaded`: enumerate `[data-analysis-status="pending"], [data-analysis-status="processing"], [data-analysis-status="null"]` → build FIFO queue of id_link integers
- [ ] 7.3 Implement `setInterval` 5000ms tick: dequeue up to 10 ids → `fetch('/modules/qameraai/products/<id>/status')` concurrently per id
- [ ] 7.4 On each response: update badge classes/label/icon, toggle Generate button enabled/disabled per `generate_enabled`, update `data-analysis-status` attribute, update Generate button `title` from response `hint`
- [ ] 7.5 If response status still in-flight → push id back to tail of queue; if settled → drop
- [ ] 7.6 Clear `setInterval` when queue empties
- [ ] 7.7 Implement per-row Refresh button click handler: disable button + spinner → `fetch('?force=1')` → same response handling → re-enable
- [ ] 7.8 If `refresh_error` is present in response, surface a non-blocking Bootstrap toast (or `PrestaShop.module.displayWarningMessage` equivalent — confirm available in PS 8.x BO)
- [ ] 7.9 Load the JS asset from `ProductsGridController::indexAction()` via the existing `addJS()` pattern used by the generate form
- [ ] 7.10 Manual smoke test (browser): grid with mix of pending + described rows → confirm pending rows tick to described within 5s of upstream flip; confirm Refresh button bypasses TTL on a described row

## 8. Contract + integration regression

- [ ] 8.1 Run full PHPUnit suite (`docker run --rm -v "$PWD:/app" -w /app php:8.1-cli vendor/bin/phpunit`) — green on PHP 8.1
- [ ] 8.2 Re-run on PHP 8.2 + 8.3 to mirror CI matrix
- [ ] 8.3 Run PHPStan level 5 (`vendor/bin/phpstan analyse`) — green
- [ ] 8.4 Run PHPCS (`vendor/bin/phpcs`) — green
- [ ] 8.5 Re-run `tests/Contract/QameraApiContractTest.php` against updated `products-detail` fixture — green
- [ ] 8.6 Confirm existing webhook flow tests still pass (no regression in `job.*` handlers from the canGenerate change)

## 9. Smoke (operator-driven, against live `qamera.ai` install)

- [ ] 9.1 Bring up local dev container (`make up` from parent shell after `composer install` in this dir)
- [ ] 9.2 Install/upgrade module → verify migration ran (check `SHOW COLUMNS FROM ps_qamera_product_link`)
- [ ] 9.3 Sync a fresh product via the BO product save hook → confirm new row has `analysis_status=NULL`
- [ ] 9.4 Open Products grid → confirm row shows Pending badge with Refresh button; click Refresh → confirm badge flips to processing/described per upstream's actual state
- [ ] 9.5 Wait until backend completes Gemini analysis → confirm JS poll auto-flips badge to Ready without page reload; Generate button enables
- [ ] 9.6 Click Generate → form opens → submit → confirm packshot job kicks off without `PREPARE_PHOTOS_TIMEOUT` failure (regression target of this whole change)
- [ ] 9.7 Bulk-select 3 rows: 1 described + 1 pending + 1 unsynced → click Generate → confirm form opens with 1 subject and flash-info `"2 products excluded (1 unsynced, 1 awaiting analysis)"`

## 10. Branch wrap-up

- [ ] 10.1 Verify `openspec validate add-analysis-status-surfacing` is clean
- [ ] 10.2 Commit on `add-analysis-status-surfacing` branch with conventional-commits message `feat(analysis-status-surfacing): ...`
- [ ] 10.3 Open PR against `main`; CI must be green on PHP 8.1/8.2/8.3 matrix
- [ ] 10.4 Archive the change with `/opsx:archive add-analysis-status-surfacing` after merge
