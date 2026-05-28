## Why

Discovered during `add-analysis-status-surfacing` live smoke (2026-05-28): every `photo_shoot` job submitted from the plugin fails upstream with `error.code=generation_failed`, `message="No source upload found at prefix '<account>/<install>/<uuid>'"`. Root cause: `ProductImageSyncService::persistSuccess()` writes `ImageResponse.imageId` (logical PK of the image record) into the `qamera_image_id` column, but `PackshotJobSubmitter` then sends that value as `Subject.packshot_asset_id`. Backend storage knows the uploaded bytes under a different `asset_id` (the UUID minted by `requestUpload()` for the pre-signed PUT) and finds nothing under the imageId, so no `photo_shoot` job has ever succeeded. The correct `asset_id` IS already in scope at upload time — `QameraApiClient::requestUpload()` returns `PresignedUploadResponse.assetId` — but the plugin discards it after the PUT.

Unblocking this is a prerequisite for `add-packshot-acceptance-flow` (next planned major change): the acceptance gate still resolves storage by `packshot_asset_id`, so without the fix every accepted packshot still fails the photo-shoot stage.

## What Changes

- Add column `qamera_asset_id CHAR(36) NULL` to `ps_qamera_product_link` (idempotent migration via `INFORMATION_SCHEMA.COLUMNS` probe + `upgrade-1.X.0.php`).
- `ProductImageSyncService::persistSuccess()` writes `PresignedUploadResponse.assetId` (already in scope as `$assetId` local) into the new column alongside the existing `qamera_image_id` write.
- `SyncedProductLink` exposes `qameraAssetId: ?string` accessor; `SyncedProductLinkLookup` SELECTs the new column on both `listForGrid()` and `loadByProductIds()` paths.
- `PackshotJobSubmitter` reads `$link->qameraAssetId` (not `qameraImageId`) into `Subject.packshotAssetId`. **BREAKING** for any test or caller currently depending on the imageId being sent as packshot_asset_id (none in production code — only fake fixtures).
- Backfill existing rows: one-shot migration that, for each row where `qamera_image_id IS NOT NULL` and `qamera_asset_id IS NULL`, calls `QameraApiClient::getProduct($link->qameraProductRef)` and reads `images[0].assetId` into the new column. Idempotent and resumable.
- Update unit tests in `tests/Unit/Packshot/` to round-trip two distinct UUIDs (imageId vs assetId) through fake fixtures and assert PackshotJobSubmitter sends the asset_id.

## Capabilities

### New Capabilities

(none — pure fix-in-place across existing capabilities)

### Modified Capabilities

- `product-image-sync`: persistence contract grows to also store the storage `asset_id` returned by `requestUpload()`; new requirement on `ps_qamera_product_link` schema (additional column + migration).
- `packshot-jobs`: `Subject.packshot_asset_id` is now sourced from the upload-time storage id, not the image logical PK. New requirement / requirement update on submitter input contract.

## Impact

- **Code**: `src/Sync/ProductImageSyncService.php`, `src/Install/Installer.php`, new `upgrade/upgrade-1.X.0.php`, `src/Packshot/SyncedProductLink.php`, `src/Packshot/SyncedProductLinkLookup.php`, `src/Packshot/PackshotJobSubmitter.php`, `tests/Unit/Packshot/PackshotJobSubmitterTest.php`, `tests/Unit/Packshot/Fixtures/FakeSyncedProductLinkLookup.php`.
- **Schema**: additive column on `ps_qamera_product_link` — zero downtime, no data destruction.
- **Operator action required after deploy**: trigger the backfill (CLI command or admin button — TBD in design.md) for existing installs. Pre-install rows without `qamera_product_ref` are unrecoverable and remain unfixable until re-synced.
- **Upstream contract**: none — uses already-shipped fields (`PresignedUploadResponse.assetId`, `ProductImageDto.assetId`).
- **Blocks**: `add-packshot-acceptance-flow` cannot deliver working photo_shoot without this landed first.
