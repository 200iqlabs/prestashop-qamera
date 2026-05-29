# Tasks — fix-packshot-catalog-registration

## 1. Submitter: register input packshot before submit (packshot-jobs) — TDD

- [ ] 1.1 Failing test: `PackshotJobSubmitter` issues `POST /packshots` (`external_ref='ps:<s>:<p>:packshot:src'`, `product_ref`, `asset_id=qamera_asset_id`, no `source_image_ref`) BEFORE `submitJob()`, for each link.
- [ ] 1.2 Failing test: idempotent — `registerPackshot` returning `status='existing'` still proceeds to submit.
- [ ] 1.3 Failing test: `registerPackshot` raising `ApiException` aborts that subject (no `POST /jobs`, recorded as chunk failure, no local row).
- [ ] 1.4 Implement in `PackshotJobSubmitter::submitChunk`: call `apiClient->registerPackshot(new RegisterPackshotRequest('ps:%d:%d:packshot:src', productRef, qameraAssetId))` per link before building/submitting the job; keep the existing random `packshot_external_ref` as the OUTPUT write-back ref + `autoRegisterPackshot=true`.

## 2. Sync: stop orphaning the asset on re-sync (product-image-sync) — TDD

- [ ] 2.1 Failing test: re-sync of a `registered` link with a non-empty `qamera_asset_id` makes NO `requestUpload()`/PUT/`registerImage` call and leaves `qamera_asset_id` unchanged.
- [ ] 2.2 Failing test (regression): first sync (`pending`/`error`) still uploads + registers + stores the presigned `assetId`.
- [ ] 2.3 Implement the early-return-on-registered branch in `ProductImageSyncService` (guard before the upload block; first-sync path unchanged).

## 3. Refresher: reconcile qamera_asset_id from the catalog (product-image-sync) — TDD

- [ ] 3.1 Failing test: `AnalysisStatusRefresher` updates `qamera_asset_id` to `images[0].asset_id` when they differ.
- [ ] 3.2 Failing test: empty `images[]` leaves `qamera_asset_id` unchanged.
- [ ] 3.3 Implement: extend the product-detail handling to read `images[0].asset_id` and persist it alongside the analysis-status cache write.

## 4. Static analysis + lint

- [ ] 4.1 PHPCS clean; local PHPUnit (docker `php:8.1-cli vendor/bin/phpunit`) green. Full PHPStan-L5 + matrix in CI.

## 5. Release bookkeeping

- [ ] 5.1 No new table. Decide whether a version bump is warranted (behavior change, no schema) — if yes, bump patch + `upgrade-*.php` no-op stub; else note "code-only, no version change" in the PR.

## 6. Smoke (operator-driven) — proves the prereq end-to-end

- [ ] 6.1 Fresh product → Save (auto-register) → confirm `qamera_asset_id` matches `GET /products/{ref}` `images[0].asset_id` (no divergence).
- [ ] 6.2 Click "Generate packshot" (or trigger the submitter) → confirm a `POST /packshots {…:packshot:src}` precedes `POST /jobs`, and the job reaches `completed` (no `MISSING_CATALOG_ENTRY`).
- [ ] 6.3 Re-save the product → confirm NO new upload and `qamera_asset_id` unchanged; for an already-diverged row, confirm the next BO grid view (refresher) heals `qamera_asset_id` to the catalog value.
- [ ] 6.4 Confirm `outputs[0].url` lands in `ps_qamera_packshot_job.output_url` (the webhook path, already validated by #22 — this re-confirms it on a packshot job created via the plugin, not a hand-registered one).

> After 6.x is green, `add-packshot-acceptance-flow` is unblocked: its stage-1 packshot jobs will complete and produce review rows.
