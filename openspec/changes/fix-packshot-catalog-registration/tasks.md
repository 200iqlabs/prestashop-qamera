# Tasks — fix-packshot-catalog-registration

## 1. Submitter: register input packshot before submit (packshot-jobs) — TDD

- [x] 1.1 `testRegistersInputPackshotBeforeSubmittingJob`: asserts `registerPackshot` with `external_ref='ps:1:42:packshot:src'`, `product_ref`, `asset_id=qamera_asset_id`, no `source_image_ref`, strictly BEFORE `submitJob` (order `['register','submit']`).
- [x] 1.2 Idempotency covered by design + happy path: the submitter ignores the `created`/`existing` status (stable ref → upstream idempotent); the submit proceeds regardless of which status comes back.
- [x] 1.3 `testRegisterPackshotFailureAbortsSubmitWithNoJobAndNoRows`: `registerPackshot` throwing aborts before `submitJob` (fails the test if reached), chunk recorded failed, no local rows.
- [x] 1.4 Implemented in `PackshotJobSubmitter::submitChunk` (per-link `registerPackshot` pre-flight; random `packshotExternalRef` + `autoRegisterPackshot=true` retained as the OUTPUT write-back). Ripple: added `registerPackshot` override to both submitter-driving test stubs. (12/12 + full suite 320/320 green.)

## 2. Sync: stop orphaning the asset on re-sync (product-image-sync) — TDD

- [x] 2.1 `testRegisteredRowWithStoredAssetReSyncIsNoop`: registered + non-empty `qamera_asset_id` → no `resolve`/`uploadImage`/`registerImage`/`execute`.
- [x] 2.2 Regression retained (`testPendingRowGetsRegisteredOnSuccess`, `testErrorRowRecoversToRegisteredOnSuccess`) + `testRegisteredRowWithoutAssetFallsThroughToReRegister` (recovery: registered-without-asset still re-registers).
- [x] 2.3 Implemented: `loadBookkeepingRow` now selects `qamera_asset_id`; early-return guard before the upload block when registered + asset present.

## 3. Refresher: reconcile qamera_asset_id from the catalog (product-image-sync) — TDD

- [x] 3.1 `testReconcilesDivergedAssetIdFromCatalog`: UPDATE carries `qamera_asset_id = '<images[0].asset_id>'` when it differs from the stored value.
- [x] 3.2 `testEmptyImagesLeavesAssetIdIntact`: empty `images[]` → UPDATE omits `qamera_asset_id`.
- [x] 3.3 Implemented: `refresh()` computes the catalog asset from `images[0]`; `persist()` adds the column conditionally (present AND differs). (16/16 green.)

## 4. Static analysis + lint

- [x] 4.1 PHPCS clean on all 4 touched files (EOL normalized via phpcbf); full PHPUnit unit suite 320/320 green on PHP 8.1. Full PHPStan-L5 + 8.1/8.2/8.3 matrix runs in CI.

## 5. Release bookkeeping

- [x] 5.1 DECIDED (operator 2026-05-29): **code-only, no schema/version change** (no new table/column — `qamera_asset_id` already exists from #21). No `upgrade-*.php`, no version bump. Note in the PR.

## 6. Smoke (operator-driven) — proves the prereq end-to-end

- [ ] 6.1 Fresh product → Save (auto-register) → confirm `qamera_asset_id` matches `GET /products/{ref}` `images[0].asset_id` (no divergence).
- [ ] 6.2 Click "Generate packshot" (or trigger the submitter) → confirm a `POST /packshots {…:packshot:src}` precedes `POST /jobs`, and the job reaches `completed` (no `MISSING_CATALOG_ENTRY`).
- [ ] 6.3 Re-save the product → confirm NO new upload and `qamera_asset_id` unchanged; for an already-diverged row, confirm the next BO grid view (refresher) heals `qamera_asset_id` to the catalog value.
- [ ] 6.4 Confirm `outputs[0].url` lands in `ps_qamera_packshot_job.output_url` (the webhook path, already validated by #22 — this re-confirms it on a packshot job created via the plugin, not a hand-registered one).

> After 6.x is green, `add-packshot-acceptance-flow` is unblocked: its stage-1 packshot jobs will complete and produce review rows.
