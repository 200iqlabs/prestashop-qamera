# Tasks — add-packshot-output-downloader

TDD throughout (units in a worktree via one-shot docker, PHP 8.1/8.2/8.3; PHPCS + PHPStan L5). PrestaShop `Image`/`ImageManager`/`ImageType` access goes behind protected seams so units stub it without a live PS. No live Qamera calls in CI — Guzzle `MockHandler` only. Real download is operator smoke (§8) in the main checkout.

## 1. Schema + ledger repository (qamera-output-import)

- [ ] 1.1 Add `ps_qamera_imported_output` to `Installer` CREATE TABLE: `id` PK AUTO_INCREMENT; `qamera_job_id CHAR(36) NOT NULL`; `output_index INT UNSIGNED NOT NULL`; `output_type VARCHAR(64) NOT NULL`; `id_shop INT UNSIGNED NOT NULL`; `id_product INT UNSIGNED NOT NULL`; `id_image INT UNSIGNED NULL`; `imported_at DATETIME NOT NULL`; `UNIQUE KEY (qamera_job_id, output_index)`; KEY `(id_product, id_shop)`. No FK (PS core tables; image may be deleted). Add the matching DROP to uninstall.
- [ ] 1.2 `upgrade/upgrade-1.8.0.php` creates the table on upgrade (idempotent `CREATE TABLE IF NOT EXISTS`, same DDL as the installer). Bump module version 1.7.0 → 1.8.0 in `qameraai.php` + `config.xml`.
- [ ] 1.3 `ImportedOutputRow` value object (jobId, outputIndex, outputType, idShop, idProduct, ?idImage, importedAt).
- [ ] 1.4 `ImportedOutputRepository` (mirror the `Db`-wrapping shape of `PackshotReviewRepository`): `findByJob(string $jobId): ImportedOutputRow[]`, `importedIndexes(string $jobId): int[]`, `isImageImported(int $idImage): bool` (origin-marker lookup for the sync guard), `record(ImportedOutputRow $row): void` (INSERT; rely on the UNIQUE key for idempotency — swallow/῾ignore duplicate). Guard against the `Db::getRow` auto-LIMIT pitfall (no explicit `LIMIT 1` — see the 4.4 `c6e0beb` bug).
- [ ] 1.5 Unit tests: record + read-back, `importedIndexes` returns only present indexes, `isImageImported` true/false, duplicate `(jobId, index)` is a no-op. Live-MySQL repo smoke deferred to §8.

## 2. OutputImporter service (qamera-output-import)

- [ ] 2.1 Eligibility resolver `OutputImporter::eligibility(string $jobId): <enum/result>` — reads the local `ps_qamera_packshot_job` row + (for `job_type='packshot'`) `PackshotReviewRepository::findByJobId`; returns one of: eligible / not-completed / no-image-output / packshot-not-accepted / already-imported. Photo-shoot completed + image-output → eligible; packshot completed + `voting='accepted'` → eligible; pending/rejected/absent review → not-accepted; every image output ledgered → already-imported.
- [ ] 2.2 `OutputImporter::import(string $jobId): ImportResult` — calls `QameraApiClient::getJob($jobId)` (fresh, re-signed); parses `product_ref` via `ProductRefParser` → `(idShop, idProduct)`; aborts with a typed diagnostic on unparseable/unknown-product ref (writes nothing). Iterates `outputs[]` with index; skips indexes already in the ledger (partial-retry); branches on `type`: `image/*` → §3 import path; non-image → record ledger row with `id_image=NULL`, no placement. A per-output failure is captured (does not abort the set); returns an aggregate `ImportResult{importedImages[], skipped[], failures[], reason?}`.
- [ ] 2.3 Protected seams for all PS image operations (so 2.x units run without PS): e.g. `createImage()`, `resizeInto()`, `associate()`, `downloadToTemp()`, `isRealImage()`, `highestPosition()` — thin wrappers over `Image`/`ImageManager`/`ImageType`/`Tools::copy`, overridable in tests (same pattern as `PrimaryImageResolver`).
- [ ] 2.4 Unit tests (MockHandler for getJob): photo-shoot single-image import path writes one ledger row + one image (seams stubbed); packshot-not-accepted aborts with no writes; unparseable product_ref aborts; partial-retry skips already-ledgered indexes; non-image output → ledger row `id_image=NULL`, no image seam called; one failing output does not abort the rest; mirror `output_url` is never read (assert getJob is the source).

## 3. PS gallery write path (qamera-output-import)

- [ ] 3.1 Per-image import (mirror `AdminImportController::copyImg`): `new Image` with `id_product`, `position = highestPosition($idProduct) + 1`, `cover` untouched → `add()` → `associateTo([$idShop])` → `downloadToTemp(url)` → `isRealImage(tmp)` (reject non-image) → `resize` base `getPathForCreation().'.jpg'` → loop `ImageType::getImagesTypes('products')` resizing each derivative. No `actionWatermark` invocation. On success insert ledger row with the new `id_image`; on failure clean up the partial `Image`/temp and record a per-output failure.
- [ ] 3.2 Unit tests over the seams: position is `highest+1`, `cover` never set, `associateTo` receives the product_ref shop, every product `ImageType` gets a resize call, non-image temp is rejected before `add()` persists a usable row, failure path cleans up + reports.

## 4. BO endpoint + route + DI (qamera-bo-ui)

- [ ] 4.1 `OutputImportController::importAction(string $jobId)` → JSON `{ok, imported:[{output_index,id_image}], skipped:[], failures:[{output_index,error}], state}`; maps eligibility reasons to stable messages (Modules.Qameraai.Admin domain); 404 when no job row; 409-style payload for not-accepted/not-completed; 200 with partial flag when some outputs failed.
- [ ] 4.2 Route `_qameraai_admin_output_import` (`POST /qameraai/jobs/{jobId}/import`, `jobId` `[A-Za-z0-9._-]+`) in `config/routes.yml`; `config/services.yml` wiring for `OutputImporter`, `ImportedOutputRepository`, controller (public, all deps).
- [ ] 4.3 Controller unit/light-integration test: success, not-accepted, unknown job, partial-failure shapes.

## 5. Jobs history grid action (qamera-bo-ui)

- [ ] 5.1 `jobs_history.html.twig`: per-row "Download to shop" control with `data-job-id`; render state from a per-row import-state passed by `JobsHistoryController` (active / disabled-with-hint / imported✓→id_image / absent) per the gating rules. Add the import-URL `<script>` template + asset tag. (Coordinate with `add-jobs-history-refresh` on the same file — rebase whichever merges second.)
- [ ] 5.2 `JobsHistoryController` computes per-row import state: join the ledger (`importedIndexes`) + review voting (`acceptedRefsIn`/`findByJobId`) for the listed page of jobs (batch, not N+1).
- [ ] 5.3 `views/js/jobs_history.js`: click handler POSTs to the import endpoint, shows a spinner (tolerant of multi-second resize, no aggressive timeout), on success updates the row in place to the imported state (DOM nodes, not innerHTML — XSS-safe), on partial/failure surfaces the diagnostic.
- [ ] 5.4 Confirm the Packshots review view is unchanged (no action added there; assert it still lists only pending).

## 6. Sync loop guard (product-image-sync)

- [ ] 6.1 `ProductImageSyncService`: before upload, consult `ImportedOutputRepository::isImageImported($resolvedIdImage)`; if true, skip the upload exactly like a null resolution (log + early-return, bookkeeping status untouched). Inject the repository via services.yml.
- [ ] 6.2 Unit tests: resolved primary that is Qamera-origin → no `registerImage` call, status unchanged; non-origin primary → unchanged existing behavior (regression guard on the existing suite).

## 7. Static + full suite

- [ ] 7.1 PHPCS (PSR-12) clean; PHPStan L5 clean (new files; `src/Install/*` stays excluded). Every new PHP file starts `<?php`+`declare(strict_types=1)`.
- [ ] 7.2 Full PHPUnit green on 8.1/8.2/8.3 (worktree, one-shot docker per CI matrix row). No regressions in the existing suite.

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
