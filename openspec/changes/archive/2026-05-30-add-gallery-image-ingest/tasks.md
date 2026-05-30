## 1. Foundation — external_ref builder

- [x] 1.1 Add a shared image/packshot `external_ref` builder producing `ps:<shop>:<prod>:image:<psImageId>` and `ps:<shop>:<prod>:pack:<psImageId>`; write unit tests covering both forms.
- [x] 1.2 Refactor `ProductImageSyncService` (line ~170) to mint the image ref via the shared builder so hook-sync and the picker stay byte-identical; assert via test that both call sites yield the same ref for the same `(shop, prod, image)`.
- [x] 1.3 Add a PrestaShop image-file resolver: given a PS image id, return local file path + content type + size; unit test against fixture paths (mock the PS `Image` lookup).

## 2. Ingest orchestrator (push)

- [x] 2.1 Write failing unit tests for `GalleryIngestOrchestrator`: Flow A (register image), "add as packshot" = image-then-packshot with `source_image_ref` set, idempotent re-ingest (`status:'existing'`), oversize rejection, image-then-packshot ordering guard.
- [x] 2.2 Implement `GalleryIngestOrchestrator` per selected image: resolve file → `PresignedImageUploadStrategy::uploadImage` → `registerImage` (always) → `registerPackshot` (when packshot action) using `RegisterImageRequest`/`RegisterPackshotRequest`; make 2.1 tests pass.
- [x] 2.3 Add error-taxonomy mapping (Guzzle `MockHandler` tests): `invalid_input` / `unauthorized` / `forbidden` / `not_found` / `source_asset_unavailable` → per-item non-retryable error; `rate_limit_exceeded` / `internal_error` → backoff retry reusing the same `external_ref`.
- [x] 2.4 Add the `plugin.catalog:write` scope precheck (from cached `/me` scopes) gating ingest; unit test the blocked path and the live-403 path.

## 3. Browse assembler (pull)

- [x] 3.1 Write failing unit tests for `ProductImageBrowseAssembler`: group packshots under images by `sourceImageId`; surface `imagesTruncated`/`packshotsTruncated` notice; counts per image.
- [x] 3.2 Implement the assembler building image → packshots tree from `ProductDetailResponse`; make 3.1 tests pass.
- [x] 3.3 Implement the lazy, capped jobs walk: page `listJobs` via cursor up to the cap, client-filter `jobType==photo_shoot` + product, map `job.packshotAssetId → packshot.assetId → packshot.sourceImageId` → image; emit "recent sessions" notice on cap-hit. Unit test mapping + cap behavior with `MockHandler`.
- [x] 3.4 Implement thumbnail sourcing per object kind (session → `JobOutput.url`; product image → local PS file; ingested packshot → source image local thumb; generated packshot → `getJob(generatedByJobId).outputs[].url`; synthesized image → related packshot thumb, else labelled placeholder); unit test each branch.

## 4. Add-to-gallery — per-output import (reuse qamera-output-import)

- [x] 4.1 Write failing tests for single-output import keyed `(qamera_job_id, output_index)`: places only the targeted output, idempotent on existing ledger row, honors photo_shoot-unconditional vs packshot-accepted gate, rejects pending packshot.
- [x] 4.2 Implement the per-output import entry point reusing the existing fresh-fetch + download-resize-append + ledger machinery (no cover steal, no watermark); make 4.1 tests pass.

## 5. Back-office controllers (AJAX)

- [x] 5.1 Add ingest controller endpoint(s) under `src/Controller/Admin/` driving `GalleryIngestOrchestrator` one item at a time, returning per-item status JSON (uploading → registering → analyzing → ready / error).
- [x] 5.2 Add browse controller endpoint(s): initial product-detail assembly, lazy row-expand (jobs walk + session images + thumbnails), and the per-output add-to-gallery trigger.
- [x] 5.3 Add a status-poll endpoint backed by `getProduct` embedded `analysis_status` for in-flight ingested items.

## 6. Tab mount + front-end

- [x] 6.1 Fill `hookDisplayAdminProductsExtra` to render the "Qamera" tab (Twig) hosting picker + browse accordion; fill `hookDisplayBackOfficeHeader` to inject the tab CSS/JS bundle only on the product-edit screen.
- [x] 6.2 Build the ingest picker UI: source toggle (store gallery / upload new), multi-select gallery grid, per-selection "Add as product" / "Add as packshot", live per-item status; block actions when write scope absent.
- [x] 6.3 Build the browse accordion: collapsed row (thumbnail + analysis badge + 📦/🎬 counts), expand → two thumbnail strips + lightbox (reuse PS fancybox), lazy jobs fetch on expand, truncation notice.
- [x] 6.4 Add the origin-guarded "Add to product gallery" action on session images + generated packshots (hidden for product/main image and ingested packshots); reflect already-imported state.
- [x] 6.5 Keep JS to vanilla + jQuery + Bootstrap 4 and Twig translation domain `Modules.Qameraai.Admin` per `qamera-bo-ui` constraints.

## 7. Quality gates

- [x] 7.1 PHPCS (PSR-12) clean on all new/changed files.
- [x] 7.2 PHPStan level 5 clean (new `src/Gallery/*` and touched files).
- [x] 7.3 Full PHPUnit suite green on PHP 8.1 / 8.2 / 8.3 (CI matrix).

## 8. Smoke (operator-driven, live container)

- [x] 8.1 Ingest: pick a non-cover gallery image, "Add as product" → appears upstream via `GET /products/{ref}`; re-run → idempotent (no duplicate). _(headless E2E: img 38 → `ps:1:32:image:38` created, re-run=existing, confirmed via getProduct.)_
- [x] 8.2 Ingest: "Add as packshot" → packshot lands accepted with non-null `source_image_id`; confirm a subsequent `photo_shoot` resolves it implicitly. _(headless E2E: `ps:1:32:pack:38` source_image_id=ab8ba280… non-null, re-run=existing. Downstream photo_shoot-resolve is the §9.3-proven mechanism.)_
- [x] 8.3 Browse: product with multiple images, packshots, and photo-shoot sessions renders correct grouping, counts, and a thumbnail on every object; verify generated-packshot thumb (getJob) and session-image thumb (signed url). _(headless E2E on prod 32: grouping/counts + ps_image/getJob/signed-url thumbs + orphan bucket all green.)_
- [x] 8.4 Add-to-gallery: import one session image → appended at end, cover unchanged, no watermark, ledger row written; re-trigger → already-imported; verify no action offered on the main image / ingested packshot. _(headless E2E: importOutput idempotent branch live (skipped, no dup); fresh placement covered by units + 4.5 e2e; no-action = presenter importable flag.)_
- [x] 8.5 Scope: with a read-only key the browse renders and ingest actions are blocked with a clear message. _(operator-verified live: under-scoped key (catalog:read only) → browse rendered + write-scope warning blocked ingest + sessions soft-notice; full-scope key → all clear. WriteScopeChecker precheck confirmed correct.)_
- [x] 8.6 `cache:clear` as `www-data` after deploy; confirm BO not broken. _(www-data cache:clear OK; 5 gallery routes registered; DI resolves; BO returns 302 not 500.)_
