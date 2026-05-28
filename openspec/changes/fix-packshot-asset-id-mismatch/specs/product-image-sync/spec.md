## MODIFIED Requirements

### Requirement: Product image upload triggers upstream registration when auto-register is enabled

When the `actionWatermark` PrestaShop hook fires with parameters `id_image` and `id_product`, and `Configuration::get('QAMERAAI_AUTO_REGISTER_PRODUCTS')` evaluates truthy, and the bookkeeping row for that `(id_product, id_shop)` has `status='pending'` or `status='error'`, the module SHALL register the product upstream by uploading the primary image via the Qamera AI Plugin API. The flow is: (1) resolve the bookkeeping row, (2) resolve the primary image for the product, (3) request a presigned upload via `QameraApiClient::requestUpload()` — which returns `PresignedUploadResponse` with `uploadUrl` (target for PUT), `assetId` (the canonical storage handle the upstream uses to resolve the uploaded bytes), and `expiresAt`, (4) PUT the image bytes to `uploadUrl`, (5) call `QameraApiClient::registerImage` with `product_ref` from the row, `asset_id` equal to the `assetId` from the presigned response, `external_ref` deterministically derived from `<product_ref>:image:<id_image>`, and `product_metadata` built from the row's snapshot columns, (6) persist the upstream result to the bookkeeping row — storing the **storage `asset_id` from the presigned response** (the value that drives later `packshot_asset_id` resolution) into `qamera_asset_id`, NOT the logical `ImageResponse.imageId`. The `productId` from the response is still used for the `pending`/`error` → `registered` transition. When the toggle evaluates falsy, the hook SHALL be a no-op. When the bookkeeping row already has `status='registered'` and `qamera_product_id IS NOT NULL`, the call SHALL still register the new image but SHALL OMIT `product_metadata` from the request (the product is already known upstream), and SHALL refresh `qamera_asset_id` with the new presigned `assetId`.

The logical `ImageResponse.imageId` SHALL NOT be persisted to the bookkeeping row. The `ImageResponse` DTO MAY retain the field as a faithful mirror of the upstream `POST /images` response, but the sync service SHALL ignore it for persistence.

#### Scenario: First image upload on a pending product registers it upstream and stores the storage asset_id

- **GIVEN** a bookkeeping row for `(id_product=42, id_shop=1)` exists with `status='pending'`, `qamera_product_id=NULL`, `display_name_snapshot='Widget'`, `sku_snapshot='WDG-001'`, `description_snapshot='hello'`
- **AND** the operator has `QAMERAAI_AUTO_REGISTER_PRODUCTS=1` and uploads an image for product 42 in the BO
- **AND** `requestUpload()` returned `PresignedUploadResponse` with `assetId='asset-uuid'`
- **WHEN** `actionWatermark` fires with `id_product=42, id_image=99` and the upstream `POST /images` returns 201 with `{product_id: 'abc-uuid', image_id: 'img-uuid', ...}`
- **THEN** the request to `/images` carries `external_ref='ps:1:42:image:99'`, `product_ref='ps:1:42'`, `asset_id='asset-uuid'`, and `product_metadata={display_name:'Widget', sku:'WDG-001', description:'hello'}`
- **AND** after the response the bookkeeping row has `status='registered'`, `qamera_product_id='abc-uuid'`, `qamera_asset_id='asset-uuid'` (the storage asset id, NOT `'img-uuid'`), `last_synced_at=NOW()`, `last_error_message=NULL`

#### Scenario: Subsequent image upload on a registered product omits product_metadata and refreshes the asset id

- **GIVEN** a bookkeeping row with `status='registered'`, `qamera_product_id='abc-uuid'`
- **AND** the new upload's `requestUpload()` returned `assetId='asset-uuid-2'`
- **WHEN** the operator uploads a second image and `actionWatermark` fires
- **THEN** the request to `/images` carries `product_ref='ps:1:42'`, `asset_id='asset-uuid-2'`, and a per-image `external_ref` but does NOT include `product_metadata`
- **AND** the bookkeeping row keeps `status='registered'`, `qamera_product_id='abc-uuid'` unchanged; `qamera_asset_id` is refreshed to `'asset-uuid-2'`; `last_synced_at` is bumped to NOW()

#### Scenario: Toggle off, no upstream call

- **WHEN** an administrator with `QAMERAAI_AUTO_REGISTER_PRODUCTS=0` uploads an image
- **THEN** no HTTP request is made to `qamera.ai` and the bookkeeping row's `status` is unchanged

#### Scenario: Image upload for a product with no bookkeeping row

- **GIVEN** a product whose `(id_product, id_shop)` is not present in `ps_qamera_product_link` (toggle was off when the product was created, then was turned on later)
- **WHEN** the operator uploads an image (toggle now on)
- **THEN** the hook handler SHALL NOT attempt registration (there is no snapshot to send); instead it SHALL emit a single `PrestaShopLogger::addLog` entry at severity 1 with `object_type='QameraAiModule'`, `object_id=<idProduct>`, `allowDuplicate=true`, and a message of the form `'[QameraAi] no bookkeeping row for id_product=<n>, id_shop=<n>; skipping image sync. Next actionProductSave will create the row.'`. Then return without further work.

### Requirement: Per-link Generate-readiness gate requires described analysis

`SyncedProductLink::canGenerate()` SHALL return true iff `$this->qameraAssetId !== null AND $this->qameraAssetId !== '' AND $this->analysisStatus === 'described'`. Any other combination (NULL/empty asset id, NULL analysis_status, or any non-`described` analysis status) SHALL return false. The gate keys on the storage `asset_id` — the value that actually drives a successful job — so a row that lacks it can never present an enabled Generate action that would later fail at generation.

`SyncedProductLink` SHALL expose a method `getDisabledHint(): ?string` returning the operator-facing hint string for the disabled-button state, with the mapping defined by the qamera-bo-ui spec. The mapping is owned by THIS link's state — the BO controller only reads it. A NULL/empty `qamera_asset_id` takes precedence over analysis state and SHALL yield the "Sync this product first" hint (the same precedence the analysis-status surfacing defined for a missing image marker).

`SyncedProductLinkLookup::listForGrid()` SHALL include `qamera_asset_id` in the SELECT and populate it on the constructed `SyncedProductLink` instances. `SyncedProductLinkLookup::loadByProductIds()` SHALL also include `qamera_asset_id` so the bulk-select path uses the same data.

#### Scenario: Asset id present and described enables generate

- **GIVEN** a `SyncedProductLink` with `qameraAssetId='asset-uuid'` and `analysisStatus='described'`
- **WHEN** `canGenerate()` is called
- **THEN** the result is `true`

#### Scenario: Asset id present but processing blocks generate

- **GIVEN** a `SyncedProductLink` with `qameraAssetId='asset-uuid'` and `analysisStatus='processing'`
- **WHEN** `canGenerate()` is called
- **THEN** the result is `false`
- **AND** `getDisabledHint()` returns "Image is being analysed…" (or its translated variant)

#### Scenario: Asset id present but NULL analysis_status blocks generate

- **GIVEN** a `SyncedProductLink` with `qameraAssetId='asset-uuid'` and `analysisStatus=NULL` (never refreshed)
- **WHEN** `canGenerate()` is called
- **THEN** the result is `false`
- **AND** `getDisabledHint()` returns "Awaiting analysis status — refresh"

#### Scenario: Asset id present but error blocks generate with re-sync hint

- **GIVEN** a `SyncedProductLink` with `qameraAssetId='asset-uuid'` and `analysisStatus='error'`
- **WHEN** `canGenerate()` is called
- **THEN** the result is `false`
- **AND** `getDisabledHint()` returns "Image analysis failed — re-sync product"

#### Scenario: Asset id absent always blocks generate regardless of analysis_status

- **GIVEN** a `SyncedProductLink` with `qameraAssetId=NULL` and (e.g. after migration, impossibly) `analysisStatus='described'`
- **WHEN** `canGenerate()` is called
- **THEN** the result is `false`
- **AND** `getDisabledHint()` returns "Sync this product first" (missing asset id takes precedence)

#### Scenario: Legacy row nulled by the migration shows the sync hint until re-saved

- **GIVEN** a row migrated from `qamera_image_id` whose `qamera_asset_id` was nulled by `upgrade-1.5.0.php`
- **WHEN** the grid renders the row
- **THEN** the Generate action is disabled with "Sync this product first"
- **AND** after the operator re-saves the product, the `actionWatermark` hook repopulates `qamera_asset_id` and the action becomes enabled (subject to analysis state)

## ADDED Requirements

### Requirement: ps_qamera_product_link stores the upstream storage asset id under qamera_asset_id

`ps_qamera_product_link` SHALL carry a column `qamera_asset_id CHAR(36) NULL` that holds the storage `asset_id` returned by `QameraApiClient::requestUpload()` for the product's primary uploaded image. This is the value sent as `Subject.packshot_asset_id` on job submission. NULL means "never synced an image upstream (or migrated and awaiting re-sync)".

This column REPLACES the former `qamera_image_id` column (which incorrectly stored the logical `ImageResponse.imageId`). `Installer::createTables()` SHALL define the column as `qamera_asset_id` on fresh installs, and the `migrateProductLinkSchema()` additions array SHALL key on `qamera_asset_id` so the idempotent ADD path never resurrects the old name.

A migration (`upgrade-1.5.0.php`) SHALL, guarded by `INFORMATION_SCHEMA.COLUMNS`:

1. If `qamera_image_id` is present AND `qamera_asset_id` is absent, rename it in place via `ALTER TABLE ... CHANGE COLUMN qamera_image_id qamera_asset_id CHAR(36) NULL`.
2. `UPDATE ... SET qamera_asset_id = NULL` — the carried-over logical-id values are wrong and MUST NOT survive (a non-null wrong value would pass the Generate gate and reproduce the silent generation failure).

The migration SHALL be idempotent: re-running it after the rename is a no-op (the guard in step 1 fails because `qamera_image_id` is gone). On any failed statement it SHALL log at severity 3 and return false, matching the `upgrade-1.4.0.php` convention.

#### Scenario: Fresh install creates qamera_asset_id and never qamera_image_id

- **GIVEN** the module is installed on a database where `ps_qamera_product_link` does not exist
- **WHEN** `Installer::createTables()` runs
- **THEN** the table has a `qamera_asset_id CHAR(36) NULL` column and no `qamera_image_id` column

#### Scenario: Upgrade renames the column and nulls stale values

- **GIVEN** a pre-existing `ps_qamera_product_link` with a `qamera_image_id` column holding logical image ids
- **WHEN** `upgrade-1.5.0.php` runs
- **THEN** the column is renamed to `qamera_asset_id` and every row's `qamera_asset_id` is NULL
- **AND** there is no `qamera_image_id` column afterward

#### Scenario: Upgrade is idempotent

- **GIVEN** an install already migrated to `qamera_asset_id`
- **WHEN** `upgrade-1.5.0.php` runs again
- **THEN** no `ALTER`/`UPDATE` fails and the schema is unchanged
