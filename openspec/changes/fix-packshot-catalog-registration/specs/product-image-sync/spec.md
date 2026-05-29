## MODIFIED Requirements

### Requirement: Product image upload triggers upstream registration when auto-register is enabled

When the `actionWatermark` PrestaShop hook fires with parameters `id_image` and `id_product`, and `Configuration::get('QAMERAAI_AUTO_REGISTER_PRODUCTS')` evaluates truthy, and the bookkeeping row for that `(id_product, id_shop)` has `status='pending'` or `status='error'`, the module SHALL register the product upstream by uploading the primary image via the Qamera AI Plugin API. The flow is: (1) resolve the bookkeeping row, (2) resolve the primary image for the product, (3) request a presigned upload via `QameraApiClient::requestUpload()` — which returns `PresignedUploadResponse` with `uploadUrl` (target for PUT), `assetId` (the canonical storage handle the upstream uses to resolve the uploaded bytes), and `expiresAt`, (4) PUT the image bytes to `uploadUrl`, (5) call `QameraApiClient::registerImage` with `product_ref` from the row, `asset_id` equal to the `assetId` from the presigned response, `external_ref` deterministically derived from `<product_ref>:image:<id_image>`, and `product_metadata` built from the row's snapshot columns, (6) persist the upstream result to the bookkeeping row — storing the **storage `asset_id` from the presigned response** (the value that drives later `packshot_asset_id` resolution) into `qamera_asset_id`, NOT the logical `ImageResponse.imageId`. The `productId` from the response is still used for the `pending`/`error` → `registered` transition. When the toggle evaluates falsy, the hook SHALL be a no-op.

When the bookkeeping row already has `status='registered'`, `qamera_product_id IS NOT NULL`, **and a non-empty `qamera_asset_id`**, the service SHALL NOT mint a new presigned upload and SHALL NOT overwrite `qamera_asset_id`. The image's deterministic `external_ref` (`<product_ref>:image:<id_image>`) makes re-registration an idempotent upstream no-op, so re-uploading only orphans a fresh asset and drifts `qamera_asset_id` away from the catalog's authoritative asset (the divergence proved in the 2026-05-29 smoke: local `c8f9950b…` vs catalog `7458837f…`). A re-sync of an already-registered product is therefore a no-op for the stored asset. New bytes are uploaded only on **first** registration (`status` in `pending`/`error`). (v1 limitation: an in-place image change under the same `id_image` is not auto-detected; recovery is delete-local-link + re-save, or the catalog reconcile below. Authoritative reconciliation of `qamera_asset_id` to the catalog is owned by the AnalysisStatusRefresher requirement.)

The logical `ImageResponse.imageId` SHALL NOT be persisted to the bookkeeping row. The `ImageResponse` DTO MAY retain the field as a faithful mirror of the upstream `POST /images` response, but the sync service SHALL ignore it for persistence.

#### Scenario: First image upload on a pending product registers it upstream and stores the storage asset_id

- **GIVEN** a bookkeeping row for `(id_product=42, id_shop=1)` exists with `status='pending'`, `qamera_product_id=NULL`, `display_name_snapshot='Widget'`, `sku_snapshot='WDG-001'`, `description_snapshot='hello'`
- **AND** the operator has `QAMERAAI_AUTO_REGISTER_PRODUCTS=1` and uploads an image for product 42 in the BO
- **AND** `requestUpload()` returned `PresignedUploadResponse` with `assetId='asset-uuid'`
- **WHEN** `actionWatermark` fires with `id_product=42, id_image=99` and the upstream `POST /images` returns 201 with `{product_id: 'abc-uuid', image_id: 'img-uuid', ...}`
- **THEN** the request to `/images` carries `external_ref='ps:1:42:image:99'`, `product_ref='ps:1:42'`, `asset_id='asset-uuid'`, and `product_metadata={display_name:'Widget', sku:'WDG-001', description:'hello'}`
- **AND** after the response the bookkeeping row has `status='registered'`, `qamera_product_id='abc-uuid'`, `qamera_asset_id='asset-uuid'` (the storage asset id, NOT `'img-uuid'`), `last_synced_at=NOW()`, `last_error_message=NULL`

#### Scenario: Re-sync of an already-registered product does not re-upload and keeps the catalog asset

- **GIVEN** a bookkeeping row with `status='registered'`, `qamera_product_id='abc-uuid'`, `qamera_asset_id='asset-uuid'`
- **WHEN** the operator re-saves the product and `actionWatermark` fires again for the same primary image
- **THEN** NO presigned upload is requested and NO image bytes are PUT
- **AND** the bookkeeping row keeps `status='registered'`, `qamera_product_id='abc-uuid'`, and `qamera_asset_id='asset-uuid'` unchanged (no orphaned re-upload)

#### Scenario: Toggle off, no upstream call

- **WHEN** an administrator with `QAMERAAI_AUTO_REGISTER_PRODUCTS=0` uploads an image
- **THEN** no HTTP request is made to `qamera.ai` and the bookkeeping row's `status` is unchanged

#### Scenario: Image upload for a product with no bookkeeping row

- **GIVEN** a product whose `(id_product, id_shop)` is not present in `ps_qamera_product_link` (toggle was off when the product was created, then was turned on later)
- **WHEN** the operator uploads an image (toggle now on)
- **THEN** the hook handler SHALL NOT attempt registration (there is no snapshot to send); instead it SHALL emit a single `PrestaShopLogger::addLog` entry at severity 1 with `object_type='QameraAiModule'`, `object_id=<idProduct>`, `allowDuplicate=true`, and a message of the form `'[QameraAi] no bookkeeping row for id_product=<n>, id_shop=<n>; skipping image sync. Next actionProductSave will create the row.'`. Then return without further work.

## ADDED Requirements

### Requirement: AnalysisStatusRefresher reconciles qamera_asset_id to the catalog asset

When `AnalysisStatusRefresher` pulls `GET /products/{product_ref}` for the analysis-status cache, it SHALL also read `images[0].asset_id` — the authoritative catalog asset under the plugin's flattened single-image-per-product model — and, when it differs from the stored `qamera_asset_id`, update `qamera_asset_id` to that catalog value. This reconciles any pre-existing divergence (e.g. rows orphaned by the pre-fix re-upload behavior) at no extra round-trip, and is the authoritative source for `packshot_asset_id`.

If the product detail carries an empty `images[]`, the refresher SHALL leave `qamera_asset_id` unchanged (it MUST NOT null a working value on a transient/partial read).

#### Scenario: Refresher heals a diverged asset id from the catalog

- **GIVEN** a bookkeeping row with `qamera_asset_id='c8f9950b…'` (an orphaned local upload)
- **AND** `GET /products/ps:1:27` returns `images[0].asset_id='7458837f…'` (the catalog asset)
- **WHEN** the refresher processes the product detail
- **THEN** the row's `qamera_asset_id` is updated to `'7458837f…'`

#### Scenario: Refresher with empty images leaves the asset id intact

- **GIVEN** a bookkeeping row with `qamera_asset_id='7458837f…'`
- **AND** `GET /products/ps:1:27` returns an empty `images[]`
- **THEN** the row's `qamera_asset_id` remains `'7458837f…'`
