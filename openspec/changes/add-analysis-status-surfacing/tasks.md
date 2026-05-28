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

- [x] 5.1 Created `src/Controller/Admin/ProductStatusController.php` extending `FrameworkBundleAdminController`.
- [x] 5.2 Route `GET /qameraai/products/{idLink}/status` (digits-only `idLink` requirement) registered as `_qameraai_admin_product_status` in `config/routes.yml`.
- [x] 5.3 Implements: shop scoping via Context, 404 JSON on `findByIdLink()` miss, force param from `?force=1`, `AnalysisStatusRefresher::refresh()` call, JSON response with the documented envelope. Note: dropped `analyzed_at` from per-row payload — it's per-image, not aggregate-derivable from the local cache; tooltip uses `analysis_refreshed_at` ("last checked at") instead.
- [x] 5.4 `Cache-Control: private, max-age=5` via `JsonResponse::setPrivate()` + `setMaxAge(5)`.
- [ ] 5.5 Integration test deferred to section 9 (smoke). Controller is thin glue over the unit-covered refresher; HTTP-layer test would need the PS Symfony bundle harness.
- [x] 5.6 Service wiring added to `config/services.yml` (`AnalysisStatusRefresher` block — explicit `$tablePrefix` constructor arg, autowire-incompatible).

## 6. Products grid controller + template update

- [x] 6.1 Row dict extended with `analysis_status`, `analysis_described_count`, `analysis_total_count`, `analysis_refreshed_at`. Dropped `analyzed_at` per 5.3.
- [x] 6.2 `disabled_hint` populated from `SyncedProductLink::getDisabledHint()` and passed to template.
- [x] 6.3 Bulk filter implemented as `GenerateFormController::filterGeneratableAndFlash()` (called from `showAction`). Partitions into `[generatable, unsynced, awaiting_analysis]`; flash composition: combined when both counts > 0, single-reason otherwise, silent on zero exclusions.
- [x] 6.4 Twig template gained "Analysis" column with badge cell + `data-analysis-status` + `data-id-link`; multi-image `(k of n)` suffix when `analysis_total_count > 1`.
- [x] 6.5 Generate button `title` reads `row.disabled_hint` with fallback to "Sync this product first".
- [x] 6.6 Per-row Refresh button (`<i class="material-icons">refresh</i>`, btn-outline-secondary) added with class `js-qameraai-refresh-analysis` + `data-id-link`.
- [ ] 6.7 Translation XLF entries deferred — English keys are already meaningful and PS falls back to the key on missing PL translation. A cosmetic PL polish commit can land separately when ready.

## 7. Grid JS poll

- [x] 7.1 Created `views/js/products_grid.js` — vanilla ES5/ES6, no NPM, no build. Co-locates bulk-select + Refresh handler + JS poll; template's inline `<script>` shrinks to a config bootstrap (`window.QameraAiProductsGrid = {statusUrlTemplate: ...}`).
- [x] 7.2 `collectInFlightRows()` enumerates `[data-analysis-status="pending"|"processing"|"null"]` into a FIFO queue keyed by `data-id-link`.
- [x] 7.3 `setInterval(POLL_INTERVAL_MS=5000)` dequeues `ROWS_PER_CYCLE=10` per tick; fires concurrent `fetch()`.
- [x] 7.4 `applyStatusToRow()` swaps badge class/label/icon + Generate button class/disabled/title from response payload; updates `data-analysis-status` attribute so subsequent polls re-select correctly.
- [x] 7.5 In-flight responses push id back to queue tail; settled responses drop. `seen{}` guard prevents duplicate-enqueue races between concurrent fetches.
- [x] 7.6 `clearInterval()` once queue empties; poller does not self-restart.
- [x] 7.7 Refresh button shares `runRefresh(idLink, urlTemplate, force=true, button)` with the poll; toggles `disabled` + `qameraai-spinner` class on the button.
- [x] 7.8 `refresh_error` surfaced via `console.warn` — non-blocking, no toast library dependency (PS BO lacks a stable warning-toast API across 8.x/9.x).
- [x] 7.9 Controller passes `js_asset_url` = `/modules/qameraai/views/js/products_grid.js`; template loads it via `<script src>` after the config bootstrap.
- [ ] 7.10 Browser smoke test deferred to section 9.

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
