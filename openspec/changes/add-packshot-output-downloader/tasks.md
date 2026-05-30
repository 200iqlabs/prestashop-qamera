# Tasks — add-packshot-output-downloader

TDD throughout (units in a worktree via one-shot docker, PHP 8.1/8.2/8.3; PHPCS + PHPStan L5). PrestaShop `Image`/`ImageManager`/`ImageType` access goes behind protected seams so units stub it without a live PS. No live Qamera calls in CI — Guzzle `MockHandler` only. Real download is operator smoke (§8) in the main checkout.

## 1. Schema + ledger repository (qamera-output-import)

- [x] 1.1 Add `ps_qamera_imported_output` to `Installer` CREATE TABLE: `id` PK AUTO_INCREMENT; `qamera_job_id CHAR(36) NOT NULL`; `output_index INT UNSIGNED NOT NULL`; `output_type VARCHAR(64) NOT NULL`; `id_shop INT UNSIGNED NOT NULL`; `id_product INT UNSIGNED NOT NULL`; `id_image INT UNSIGNED NULL`; `imported_at DATETIME NOT NULL`; `UNIQUE KEY (qamera_job_id, output_index)`; KEY `(id_product, id_shop)`. No FK (PS core tables; image may be deleted). Add the matching DROP to uninstall.
- [x] 1.2 `upgrade/upgrade-1.8.0.php` creates the table on upgrade (idempotent `CREATE TABLE IF NOT EXISTS`, same DDL as the installer). Bump module version 1.7.0 → 1.8.0 in `qameraai.php` + `config.xml`.
- [x] 1.3 `ImportedOutputRow` value object (jobId, outputIndex, outputType, idShop, idProduct, ?idImage, importedAt).
- [x] 1.4 `ImportedOutputRepository` (mirror the `Db`-wrapping shape of `PackshotReviewRepository`): `findByJob(string $jobId): ImportedOutputRow[]`, `importedIndexes(string $jobId): int[]`, `isImageImported(int $idImage): bool` (origin-marker lookup for the sync guard), `record(ImportedOutputRow $row): void` (INSERT IGNORE; rely on the UNIQUE key for idempotency — swallow duplicate). Guard against the `Db::getRow` auto-LIMIT pitfall (no explicit `LIMIT 1` — see the 4.4 `c6e0beb` bug).
- [x] 1.5 Unit tests: record + read-back, `importedIndexes` returns only present indexes, `isImageImported` true/false/non-positive guard, NULL id_image (video) row. Live-MySQL repo smoke deferred to §8.

## 2. OutputImporter service (qamera-output-import)

- [x] 2.1 Job-level gate `OutputImporter::jobGateReason(string $status, ?PackshotReviewRow): ?string` — shared by grid + import so they never disagree. completed + (no review OR accepted review) → null (pass); non-completed → `not_completed`; packshot review pending/rejected → `packshot_not_accepted`. Packshot-ness DERIVED from review-row presence (no `job_type` column exists; avoids touching the 4.4 webhook path) — see design.md.
- [x] 2.2 `OutputImporter::import(string $jobId): ImportResult` — calls `QameraApiClient::getJob($jobId)` (fresh, re-signed; ApiException → `api_error` abort); gate; parses `product_ref` via `ProductRefParser` (→ `invalid_product_ref`) and checks `SyncedProductLinkLookup::findIdLink` (→ `product_not_registered`); iterates `outputs[]` skipping ledgered indexes (partial-retry); `image/*` → §3 import path, non-image → ledger row `id_image=NULL`; per-output failure captured (does not abort set); returns `ImportResult{imported[], skipped[], recordedNonImage[], failures[], reason?}`.
- [x] 2.3 Protected seams for all PS image operations live on `GalleryImageWriter` (group 3): `highestPosition`/`createImageRow`/`associate`/`downloadToTemp`/`isRealImage`/`imageTypes`/`pathForCreation`/`resizeFile`/`deleteImageRow` — thin wrappers over `Image`/`ImageManager`/`ImageType`/`Tools::copy`, overridden by `RecordingGalleryImageWriter` in tests (PrimaryImageResolver precedent).
- [x] 2.4 Unit tests (mocked getJob): photo-shoot single-image import; accepted-packshot import; pending-packshot abort (no writes); not-completed abort; unparseable product_ref abort; product-not-registered abort; partial-retry skips ledgered indexes; non-image → ledger `id_image=NULL`, no gallery call; one failing output does not abort the rest; ApiException → graceful abort; gate pure-logic table.

## 3. PS gallery write path (qamera-output-import)

- [x] 3.1 Per-image import in `GalleryImageWriter::importImage` (mirror `copyImg`): `new Image` `position = highestPosition+1`, `cover=false` → `add()` → `associateTo([$idShop])` → `downloadToTemp(url)` → `isRealImage(tmp)` (reject non-image) → `resize` base `getPathForCreation().'.jpg'` → loop `ImageType::getImagesTypes('products')` resizing each derivative. No `actionWatermark`. On failure: `deleteImageRow` (clean up half-created row) + discard temp, rethrow. (Ledger write owned by `OutputImporter`.)
- [x] 3.2 Unit tests over the seams: position is `highest+1`, returns new id, `associateTo` receives the product_ref shop, base + every `ImageType` gets a resize call, non-real-image rejected + row cleaned up, download failure cleans up + propagates. (cover-never-set + empty-gallery front-office render confirmed in smoke §8.3.)

## 4. BO endpoint + route + DI (qamera-bo-ui)

- [x] 4.1 `OutputImportController::importAction(string $jobId)` (thin shell) → CSRF (`qamera_output_import`) + jobId guard → `OutputImporter::import` → JSON via `ImportResultPresenter`. Body `{ok, state, reason?, imported, skipped, recorded_non_image, failures}`; status per reason (409 not-accepted/not-completed, 422 invalid-ref, 404 not-registered, 502 api-error, 200 success/partial).
- [x] 4.2 Route `_qameraai_admin_output_import` (`POST /qameraai/jobs/{jobId}/import`, `jobId` `[A-Za-z0-9._-]+`) in `config/routes.yml`; `config/services.yml` wiring for `ImportedOutputRepository` + public `OutputImporter`/`ImportResultPresenter` (rest autowire).
- [x] 4.3 Mapping logic extracted to pure `ImportResultPresenter` + 10-case unit test (success/partial/already/nothing/abort-status table). Controller itself is thin + framework-bound → smoke-covered (§8), matching the no-controller-unit-test precedent in this module.

## 5. Jobs history grid action (qamera-bo-ui)

- [x] 5.1 `jobs_history.html.twig`: new "Shop import" column rendering per-row state from `import_state[qamera_job_id]` (imported✓ badge / active "Download to shop" / disabled-with-hint / absent —). Added `importUrlTemplate` + `csrf_token('qamera_output_import')` + i18n to the `window.QameraAiJobsHistory` `<script>`. (Coexists with the jobs-history-refresh refresh button already present on main.)
- [x] 5.2 `JobsHistoryController` computes per-row import state via `OutputImporter::gridState(status, findByJobId, importedIndexes)` for the page (bounded by PAGE_SIZE; per-row reads acceptable for v1, batched variant noted as future) + `import_url_template`.
- [x] 5.3 `views/js/jobs_history.js`: `initImportButtons` click handler POSTs `_token` to the import endpoint, spinner (no aggressive timeout), on success flips the cell to an "imported" badge in place (DOM nodes, not innerHTML — XSS-safe), on partial/abort re-enables + surfaces reason. `gridState` covered by `OutputImporterTest` (4 cases).
- [x] 5.4 Packshots review view untouched (no action added there; `PackshotReviewController::indexAction` still `listPending` only).

## 6. Sync loop guard (product-image-sync)

- [x] 6.1 `ProductImageSyncService`: before upload, consult `ImportedOutputRepository::isImageImported($resolvedIdImage)`; if true, skip the upload exactly like a null resolution (log + early-return, bookkeeping status untouched). Injected as 9th ctor dep + services.yml (+ ledger service def). Rippled the 8-arg constructions in the integration test + `ProductImageSyncServiceTest` (default mock isImageImported→false).
- [x] 6.2 Unit tests (`ProductImageSyncOriginGuardTest`): resolved primary that is Qamera-origin → no `uploadImage`/`registerImage` call; non-origin primary → upload fires (regression guard). Existing 438-test suite green.

## 7. Static + full suite

- [x] 7.1 PHPCS clean under CI's `phpcs.xml.dist` (no-arg `vendor/bin/phpcs`) — none of the new/edited files flagged; all new files start `<?php`+`declare(strict_types=1)`, all `i/lf` in the git index. PHPStan L5 deferred to CI (the `ps-module-extension` bootstrap requires a full PrestaShop core at `_PS_ROOT_DIR_`/`.ps-src`, which the one-shot docker runtime does not have; new code mirrors the PHPStan-passing patterns of `PackshotReviewRepository`/`PrimaryServiceImageResolver`/`JobsStatusRefresher`). `php -l` clean on all touched files.
- [x] 7.2 Full PHPUnit green on PHP **8.1/8.2/8.3** (one-shot docker per CI matrix row): 452 tests, 0 failures, 12 pre-existing skips. No regressions (+34 new tests over the 410 baseline; +4 schema/DB rows are exercised by the new repo tests, with live-MySQL repo smoke deferred to §8).

## 8. Operator smoke (main checkout, live container)

- [ ] 8.1 Upgrade module to 1.8.0 on the live container (`prestashop:module upgrade qameraai` as appropriate); verify `ps_qamera_imported_output` created with the UNIQUE key; routes + DI resolvable (cache:clear as **www-data**).
- [ ] 8.2 Live-MySQL repo smoke in a rolled-back txn: `record` + `importedIndexes` + `isImageImported` + duplicate-noop (mirrors the 4.4 getRow-LIMIT precaution).
- [ ] 8.3 End-to-end on a real completed photo-shoot job (existing synced product, e.g. 31/28): click "Download to shop" → scene appears in the product gallery appended, cover unchanged, front-office thumbnails render (all ImageType sizes on disk); ledger row written; button flips to "imported ✓"; re-click is a no-op.
- [ ] 8.4 Accepted-packshot path: a completed `job_type='packshot'` accepted in the Packshots view → its Jobs history row shows the active action → import lands the cutout in the gallery.
- [ ] 8.5 Loop-guard check: after import, a product re-save / watermark does NOT re-upload the imported scene to Qamera (verify via logs / `GET /products/{ref}` images unchanged).
- [ ] 8.6 Negative: pending/rejected packshot row shows no active action; video-output job (if available) records a ledger row with `id_image=NULL` and places nothing.

## 9. Wrap-up

- [ ] 9.1 `openspec validate add-packshot-output-downloader --strict` passes; tasks all checked.
- [ ] 9.2 Open PR; note the `jobs_history.*` rebase coordination with `add-jobs-history-refresh` in the PR body.
