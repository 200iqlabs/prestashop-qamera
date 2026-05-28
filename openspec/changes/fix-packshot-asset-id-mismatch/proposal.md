## Why

Discovered during `add-analysis-status-surfacing` live smoke (2026-05-28): every `photo_shoot` job submitted from the plugin fails upstream with `error.code=generation_failed`, `message="No source upload found at prefix '<account>/<install>/<uuid>'"`. Root cause: `ProductImageSyncService::persistSuccess()` writes `ImageResponse.imageId` (the logical PK of the image record) into the `qamera_image_id` column, but `PackshotJobSubmitter` then sends that value as `Subject.packshot_asset_id`. Backend storage knows the uploaded bytes under a different `asset_id` — the UUID minted by `requestUpload()` for the pre-signed PUT — and finds nothing under the imageId, so no `photo_shoot` job has ever succeeded. The correct `asset_id` IS already in scope at upload time (`PresignedUploadResponse.assetId`, the value the client itself PUTs to and passes to `registerImage`), but the plugin discards it after the response and persists the unrelated logical `imageId` instead.

Unblocking this is a prerequisite for `add-packshot-acceptance-flow` (the next planned change): stage 1 of that flow (`job_type='packshot'`) still resolves its source upload from `packshot_asset_id`, so without this fix every generated packshot fails at generation and nothing ever reaches the review/accept gate.

## What Changes

The plugin is **not yet commercially available — no client uses it — so there is no backward-compatibility constraint.** This lets us fix the root cause cleanly instead of layering a compatibility column on top of a misnamed one. The guiding principle is "no garbage": the column that feeds `packshot_asset_id` should *be named* `qamera_asset_id` and should *hold* the storage asset_id.

- **Rename** column `qamera_image_id` → `qamera_asset_id` on `ps_qamera_product_link` (do NOT add a second column). The logical `ImageResponse.imageId` is not stored anywhere — a grep confirms every reader of the column feeds either `Subject.packshot_asset_id` or the Generate-readiness gate; nothing consumes the logical image id as such.
- `ProductImageSyncService::persistSuccess()` writes `PresignedUploadResponse.assetId` (already in scope as the `$assetId` local in `syncOnImageAdded()`) into `qamera_asset_id`. `ImageResponse.imageId` is no longer persisted (the `ImageResponse` DTO keeps the field as an honest mirror of the upstream contract, but the sync service ignores it).
- `SyncedProductLink` renames its accessor `qameraImageId` → `qameraAssetId`; `SyncedProductLinkLookup` SELECTs `qamera_asset_id` on both `listForGrid()` and `loadByProductIds()` paths.
- `PackshotJobSubmitter` reads `$link->qameraAssetId` into `Subject.packshotAssetId`.
- `SyncedProductLink::canGenerate()` gate moves from `qameraImageId` to `qameraAssetId` (still AND `analysisStatus === 'described'`). The honest signal for "can we run a job" is "do we have the storage asset_id", not "do we have a logical image id". Legacy rows whose `qamera_asset_id` is NULL after the migration surface the existing "Sync this product first" disabled hint until the operator re-saves the product.
- **Migration** (`upgrade-1.5.0.php`, module bumped 1.4.0 → 1.5.0): INFORMATION_SCHEMA-guarded `ALTER TABLE ... CHANGE qamera_image_id qamera_asset_id CHAR(36) NULL`, then `UPDATE ... SET qamera_asset_id = NULL` — the carried-over values are the wrong (logical) ids and must not survive, or the gate would pass and the job would silently fail again. `Installer::createTables()` and `migrateProductLinkSchema()` are updated so fresh installs get `qamera_asset_id` directly.
- **No backfill script.** A resumable `GET /products/{ref}` backfill loop was the right tool for preserving *client* data; with no clients, the only affected rows are a handful of the operator's own smoke products on the live `pracownia-qamery-ai` install. The recovery path is: the migration nulls them, the operator re-saves the products, the `actionWatermark` hook re-runs the sync and persists the correct `asset_id`. Zero new code, zero residue.
- Update unit tests in `tests/Unit/Packshot/` and `tests/Unit/Sync/` to round-trip two distinct UUIDs (the discarded `imageId` vs the persisted `assetId`) and assert `PackshotJobSubmitter` sends the storage `asset_id`.

## Capabilities

### New Capabilities

(none — pure fix-in-place across existing capabilities)

### Modified Capabilities

- `product-image-sync`: the persistence contract changes to store the storage `asset_id` returned by `requestUpload()` (not the logical `imageId`); the `ps_qamera_product_link` schema requirement changes the column name; the Generate-readiness gate keys on `qamera_asset_id`.
- `packshot-jobs`: `Subject.packshot_asset_id` is now sourced from the upload-time storage id, making the submitter input contract explicit about which upstream identifier drives generation.

## Impact

- **Code**: `src/Sync/ProductImageSyncService.php`, `src/Install/Installer.php`, new `upgrade/upgrade-1.5.0.php`, `src/Packshot/SyncedProductLink.php`, `src/Packshot/SyncedProductLinkLookup.php`, `src/Packshot/PackshotJobSubmitter.php`, `src/Controller/Admin/{ProductsGridController,ProductStatusController,GenerateFormController}.php` (accessor rename), `config.xml` + `qameraai.php` version bump, tests under `tests/Unit/`.
- **Schema**: in-place column rename on `ps_qamera_product_link` — no data destruction beyond nulling the (already-wrong) carried values.
- **Operator action required after deploy**: re-save the handful of smoke-test products on `pracownia-qamery-ai` so the hook repopulates `qamera_asset_id`. (Bulk re-sync of a real catalogue is out of scope here — tracked separately as `add-bulk-sync-action`.)
- **Upstream contract**: none — uses already-shipped fields (`PresignedUploadResponse.assetId`).
- **Blocks**: `add-packshot-acceptance-flow` cannot deliver a working packshot/photo-shoot pipeline without this landed first.
