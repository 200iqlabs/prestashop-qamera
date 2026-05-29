# product-image-sync Specification

## Purpose

Defines how PrestaShop product images are pushed upstream to the Qamera AI Plugin API when the `actionWatermark` hook fires. Builds on the Phase-2 `product-sync-bookkeeping` rows: this capability owns the state transitions (`pending`/`error` → `registered`), the presigned-upload + PUT + `registerImage` orchestration, the primary-image resolution chain, in-request dedup, and the sanitized error mapping that lands on the bookkeeping row's `last_error_message`. The hook handler never lets an upstream failure bubble — back-office product saves stay successful regardless of upstream state, mirroring the Phase-2 swallow-throw contract.
## Requirements
### Requirement: Product image upload triggers upstream registration when auto-register is enabled

When the `actionWatermark` PrestaShop hook fires with parameters `id_image` and `id_product`, and `Configuration::get('QAMERAAI_AUTO_REGISTER_PRODUCTS')` evaluates truthy, and the bookkeeping row for that `(id_product, id_shop)` has `status='pending'` or `status='error'`, the module SHALL register the product upstream by uploading the primary image via the Qamera AI Plugin API. The flow is: (1) resolve the bookkeeping row, (2) resolve the primary image for the product, (3) request a presigned upload via `QameraApiClient::requestUpload()` — which returns `PresignedUploadResponse` with `uploadUrl` (target for PUT), `assetId` (the canonical storage handle the upstream uses to resolve the uploaded bytes), and `expiresAt`, (4) PUT the image bytes to `uploadUrl`, (5) call `QameraApiClient::registerImage` with `product_ref` from the row, `asset_id` equal to the `assetId` from the presigned response, `external_ref` deterministically derived from `<product_ref>:image:<id_image>`, and `product_metadata` built from the row's snapshot columns, (6) persist the upstream result to the bookkeeping row — storing the **storage `asset_id` from the presigned response** (the value that drives later `packshot_asset_id` resolution) into `qamera_asset_id`, NOT the logical `ImageResponse.imageId`. The `productId` from the response is still used for the `pending`/`error` → `registered` transition. When the toggle evaluates falsy, the hook SHALL be a no-op.

When the bookkeeping row already has `status='registered'`, `qamera_product_id IS NOT NULL`, **and a non-empty `qamera_asset_id`**, the service SHALL NOT mint a new presigned upload and SHALL NOT overwrite `qamera_asset_id`. The image's deterministic `external_ref` (`<product_ref>:image:<id_image>`) makes re-registration an idempotent upstream no-op, so re-uploading only orphans a fresh asset and drifts `qamera_asset_id` away from the catalog's authoritative asset (the divergence proved in the 2026-05-29 smoke: local `c8f9950b…` vs catalog `7458837f…`). A re-sync of an already-registered product is therefore a no-op for the stored asset. New bytes are uploaded only on **first** registration (`status` in `pending`/`error`); a registered row WITHOUT an asset still re-registers (recovery). (v1 limitation, deliberate: the plugin persists no prior `id_image`, so a changed primary image — even a NEW `id_image` — is not auto-re-synced. This does NOT break packshot submits: the retained `qamera_asset_id` still resolves to a valid catalog asset; only the new image is unreflected. Recovery is delete-local-link + re-save, or the catalog reconcile below. Authoritative reconciliation of `qamera_asset_id` to the catalog is owned by the AnalysisStatusRefresher requirement.)

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

### Requirement: Primary image resolution prefers cover image with a deterministic fallback chain

The module SHALL select the image to upload using `PrimaryImageResolver::resolve(int $idProduct, ?int $hintIdImage): ?int` (returns the resolved image id, NOT a PrestaShop `Image` instance — PS's `Image::getCover` and `Image::getImages` return associative arrays, so the resolver returns the `id_image` int extracted from those arrays). The resolver SHALL try in order: (1) `Image::getCover($idProduct)` if it returns a non-empty array, take its `id_image`; (2) the `$hintIdImage` from the hook params if it points to a valid image for that product; (3) the first image returned by `Image::getImages($idLang, $idProduct)` ordered by position, where `$idLang` is the shop's default language id resolved via `Configuration::get('PS_LANG_DEFAULT', null, null, $idShop)` — same convention as the Phase-2 snapshot writer. If all three return nothing, the resolver SHALL return null and the sync service SHALL early-return without touching the bookkeeping row's status (a missing image is not an error of the upstream sync, it is missing input).

#### Scenario: Product with a cover image

- **GIVEN** product 42 has three images and image 100 is set as cover
- **WHEN** `PrimaryImageResolver::resolve(42, 99)` is called (hint pointing to image 99, a non-cover image)
- **THEN** the resolver returns `100` (cover image id wins over hint)

#### Scenario: Product without cover, hint valid

- **GIVEN** product 42 has two images and no cover is set; the operator just uploaded image 99
- **WHEN** `PrimaryImageResolver::resolve(42, 99)` is called
- **THEN** the resolver returns `99` (hint fallback)

#### Scenario: Product with no images

- **WHEN** `PrimaryImageResolver::resolve(42, null)` is called and the product has zero images
- **THEN** the resolver returns `null` and the sync service skips the registration entirely without changing the bookkeeping row

### Requirement: Upstream errors map to the bookkeeping row with sanitized last_error_message

When the upstream registration fails — whether at the presigned-upload step, the PUT step, or the `registerImage` step — the sync service SHALL catch the exception, set the bookkeeping row's `status='error'`, `last_synced_at=NOW()`, and `last_error_message` to a sanitized string derived from the exception type and message. The mapping SHALL be deterministic so operators see consistent diagnostics across runs. `last_error_message` SHALL be truncated to 500 characters. The hook handler SHALL NOT let the exception bubble — BO save action MUST complete normally regardless of upstream state (inheriting the Phase-2 swallow-throw contract).

#### Scenario: Validation error from upstream

- **WHEN** `QameraApiClient::registerImage` throws `ValidationException` with first error code `display_name_too_long` and message `display_name exceeds 500 chars`
- **THEN** the bookkeeping row gets `status='error'`, `last_error_message` starts with `Upstream validation:` and contains the error code and message (truncated to 500 chars)
- **AND** the hook returns without re-raising; the BO save action completes successfully

#### Scenario: Auth failure

- **WHEN** `QameraApiClient::registerImage` throws `AuthException` (HTTP 401)
- **THEN** `last_error_message` is `API credentials invalid (HTTP 401). Check API key in module configuration.`

#### Scenario: Rate limit

- **WHEN** `QameraApiClient::registerImage` throws `RateLimitException` (HTTP 429)
- **THEN** `last_error_message` is `Rate limit exceeded — try again later. (HTTP 429)` and `status='error'`

#### Scenario: Server error after retries

- **WHEN** `QameraApiClient` exhausts retries and throws `ServerException` (5xx)
- **THEN** `last_error_message` is `Upstream server error (HTTP 5xx) after retries. Try again later.`

#### Scenario: Transport / connection failure

- **WHEN** Guzzle throws `ConnectException` and the client wraps it as `TransportException` with message `cURL error 7: Failed to connect to qamera.ai port 443`
- **THEN** `last_error_message` starts with `Network error reaching Qamera AI:` and includes the underlying message (truncated to 500 chars)

### Requirement: Error state transitions to registered on a successful retry

When the bookkeeping row has `status='error'` and a subsequent `actionWatermark` triggers a registration that succeeds, the sync service SHALL transition the row to `status='registered'`, populate `qamera_product_id`, clear `last_error_message` to NULL, and bump `last_synced_at`. Operators do not need to manually reset the row to `pending`.

#### Scenario: Operator fixes display name and reuploads image

- **GIVEN** a row with `status='error'`, `last_error_message='Upstream validation: display_name_too_long'`
- **AND** the operator shortens the product name (Phase-2 hook refreshes `display_name_snapshot`)
- **WHEN** the operator uploads an image and `registerImage` returns 201
- **THEN** the row has `status='registered'`, `qamera_product_id=<from response>`, `last_error_message=NULL`, `last_synced_at=NOW()`

### Requirement: Duplicate hook fires for the same image are deduplicated

PrestaShop's `actionWatermark` hook MAY fire more than once for the same `(id_product, id_image)` pair within a single request lifecycle — typically in bulk image regeneration flows in the BO. The sync service SHALL deduplicate: only the first invocation per `(id_product, id_image)` MAY trigger upstream work; subsequent invocations with the same key SHALL be no-ops. The dedup cache is in-memory per request — across requests the same `id_image` MAY register again (e.g. operator clears bookkeeping and reuploads).

The dedup rule applies only to the *same image*. Two distinct images for the same product (e.g. cover image 100 and secondary image 101) MUST each be processed independently per their own `id_image` key. The downstream-state rules for what each invocation actually does are split by bookkeeping state:

- **`pending` / `error` row (cascade-create path)**: the first successful invocation MAY upload a *different* image than the hook's `id_image` — specifically, the image returned by `PrimaryImageResolver` (cover preferred over hint). That uploaded image is the one registered with `product_metadata` to drive the upstream cascade-create. The hook's hint image is NOT separately registered in this invocation. It WILL register later — either when the operator's next image action fires another `actionWatermark` (the row is `registered` by then, so the bare-image rule below applies), or in a future bulk-sync feature.
- **`registered` row (bare-image path)**: every new `id_image` from the hook SHALL trigger a `POST /images` call without `product_metadata`. The resolver is NOT consulted. There is no "skip non-cover" rule on a registered row.

#### Scenario: Same image fires hook twice in a bulk regenerate flow

- **GIVEN** product 42 has cover image 100; the operator triggers BO "Regenerate thumbnails" which causes `actionWatermark` to fire twice with `id_image=100`
- **WHEN** the sync service handles both invocations in the same request
- **THEN** only one upstream `POST /assets/upload`, one PUT, and one `POST /images` are issued

#### Scenario: Distinct images for the same product register independently

- **GIVEN** a registered bookkeeping row (`status='registered'`, `qamera_product_id='abc-uuid'`)
- **WHEN** the operator uploads a secondary image 101 and `actionWatermark` fires with `id_image=101`
- **AND** later uploads a third image 102 and `actionWatermark` fires again with `id_image=102`
- **THEN** the sync service issues two separate `POST /images` requests — one for each new image — neither carrying `product_metadata` (the row is already registered); `last_synced_at` is bumped twice

### Requirement: Primary image is used only for the cascade-create metadata payload

When the bookkeeping row is in `status='pending'` or `status='error'` (i.e. the upstream product does NOT yet exist or the previous attempt failed), the sync service SHALL use `PrimaryImageResolver` to choose which image carries the `product_metadata` payload to upstream — cover image preferred over the hint image (see the "Primary image resolution" requirement). The asset uploaded in this case is the resolved primary image (not necessarily the image whose ID came in via the hook).

When the bookkeeping row is already `status='registered'`, the resolver is NOT consulted — every `id_image` from the hook params SHALL be uploaded as-is and registered as a new asset for the existing upstream product (without `product_metadata`).

#### Scenario: Pending row, non-cover hook fires before cover is set

- **GIVEN** a `pending` bookkeeping row; product 42 has no cover yet; the operator uploads image 99
- **WHEN** `actionWatermark` fires with `id_image=99`
- **AND** `PrimaryImageResolver::resolve(42, 99)` returns image 99 (hint fallback per the resolver's chain)
- **THEN** the sync service uploads image 99 and registers it with `product_metadata` from the row's snapshot

#### Scenario: Pending row, non-cover hook fires while a cover exists

- **GIVEN** a `pending` bookkeeping row; product 42 already has cover image 100; the operator uploads a secondary image 99
- **WHEN** `actionWatermark` fires with `id_image=99`
- **AND** `PrimaryImageResolver::resolve(42, 99)` returns image 100 (cover wins over hint)
- **THEN** the sync service uploads image 100 (not 99) and registers it with `product_metadata`
- **AND** the bookkeeping row transitions to `registered`
- **AND** image 99 is NOT registered in this invocation — it will register when its own `actionWatermark` invocation runs (already deduped by `(id_product, id_image)` from §"duplicate hook fires" above) or when the next image upload triggers another hook

#### Scenario: Registered row, every new image registers without metadata

- **GIVEN** a `registered` bookkeeping row for product 42, `qamera_product_id='abc-uuid'`
- **WHEN** the operator uploads image 105 and `actionWatermark` fires with `id_image=105`
- **THEN** the sync service uploads image 105 directly (no resolver consultation) and registers it with `external_ref='ps:1:42:image:105'`, `product_ref='ps:1:42'`, `asset_id=<assetId>`, NO `product_metadata`

### Requirement: Presigned upload TTL is honored

The sync service SHALL check `PresignedUploadResponse::$expiresAt` (the DTO property — the upstream JSON field is `expires_at`) before issuing the PUT. If `$expiresAt <= now()`, the service SHALL request a fresh presigned URL before uploading.

#### Scenario: Expired presigned URL forces refresh

- **WHEN** `requestUpload()` returns a `PresignedUploadResponse` whose `$expiresAt` parses to `now() - 1s` (already expired, e.g. clock drift)
- **THEN** the sync service requests a fresh presigned URL and uses the new one for the PUT; the upload still succeeds

### Requirement: Schema includes analysis-status cache columns on ps_qamera_product_link

`ps_qamera_product_link` SHALL carry four additional columns to cache the upstream image-analysis lifecycle:

- `analysis_status` `ENUM('pending','processing','described','error','partial') NULL DEFAULT NULL`
- `analysis_described_count` `INT UNSIGNED NULL DEFAULT NULL`
- `analysis_total_count` `INT UNSIGNED NULL DEFAULT NULL`
- `analysis_refreshed_at` `DATETIME NULL DEFAULT NULL`

`Installer::createTables()` SHALL include the columns in the fresh-install `CREATE TABLE`. A migration
method `Installer::migrateProductLinkAnalysisColumns()` SHALL emit `ALTER TABLE ... ADD COLUMN` for each
column that is not already present (checked via `INFORMATION_SCHEMA.COLUMNS`), so the upgrade path on an
existing install is additive and idempotent. The migration SHALL be invoked from the module's `upgrade`
hook in the same sequence as the existing `migratePackshotLinkSchema()`.

NULL values SHALL be interpreted as "no analysis cache yet — needs first refresh"; semantically equivalent
to `pending` for the purpose of the Generate gate (see "Per-link Generate-readiness gate" below) but
distinguishable for the JS poll selector and for logging.

The `'partial'` enum value is reserved for the multi-image future where some images in a product are
`described` and others are not. The single-image v1 flow never emits `partial`, but encoding it now
avoids a second migration when multi-image sync lands.

#### Scenario: Fresh install creates the columns

- **GIVEN** the module is installed on a database where `ps_qamera_product_link` does not yet exist
- **WHEN** `Installer::createTables()` runs
- **THEN** the resulting table has `analysis_status`, `analysis_described_count`, `analysis_total_count`, `analysis_refreshed_at` columns matching the types above

#### Scenario: Upgrade on existing install adds the columns idempotently

- **GIVEN** a pre-existing `ps_qamera_product_link` table without the analysis columns
- **WHEN** `Installer::migrateProductLinkAnalysisColumns()` runs
- **THEN** the four columns are added via `ALTER TABLE ... ADD COLUMN`
- **AND** running the same migration a second time is a no-op (no failed `ALTER`)

#### Scenario: Existing rows initialise with NULL analysis cache

- **GIVEN** a row created before the migration
- **WHEN** the migration completes
- **THEN** the row's `analysis_status`, `analysis_described_count`, `analysis_total_count`, `analysis_refreshed_at` are all NULL

### Requirement: AnalysisStatusRefresher pulls product detail and writes the aggregate cache

A service `QameraAi\Module\Sync\AnalysisStatusRefresher` SHALL expose:

```php
public function refresh(SyncedProductLink $link, bool $force = false): RefreshResult;
```

`RefreshResult` carries the post-refresh `analysisStatus`, `describedCount`, `totalCount`, `refreshedAt`,
and an optional `?string $refreshError` populated when the upstream pull failed but a cached value is
being returned.

Behaviour:

1. If `force=false` AND `$link->analysisRefreshedAt` is fresher than the per-status TTL (60s for
   `{pending, processing, NULL}`, 3600s for `{described, error, partial}`), return the cached values
   without an HTTP call.
2. Otherwise, call `QameraApiClient::getProduct($link->qameraProductRef)`. Identifier is the `ref`
   (`qameraProductRef`), NOT the `qamera_product_id`, because `ref` is always non-NULL on a registered
   link and is the canonical plugin-side identifier.
3. On success, reduce `response.images[].analysisStatus[]` to an aggregate (see "Aggregate reduction"
   requirement), UPDATE the row, return the new values.
4. On `ApiException` (any subclass), keep the cached row values, return them with `$refreshError` set to
   a sanitised message string derived from the exception type (same sanitisation conventions as the
   existing `ProductImageSyncService` error mapping — `"Upstream validation: ..."`, `"API credentials invalid (HTTP 401)..."`, etc., truncated to 500 chars).

The refresher SHALL NOT bubble `ApiException`. It SHALL log the failure via `PrestaShopLoggerWrapper` at
severity 2 (warning) so failed refreshes are diagnosable without spamming the BO.

#### Scenario: TTL-fresh row returns cached values without HTTP call

- **GIVEN** a link with `analysisStatus='processing'`, `analysisRefreshedAt=NOW() - 10s`
- **WHEN** `refresh($link, force: false)` is called
- **THEN** no HTTP request is issued
- **AND** the returned `RefreshResult` carries the cached values
- **AND** `analysisRefreshedAt` on the row is unchanged

#### Scenario: TTL-stale processing row pulls fresh and writes back

- **GIVEN** a link with `analysisStatus='processing'`, `analysisRefreshedAt=NOW() - 90s`
- **WHEN** `refresh($link, force: false)` is called and upstream returns `images[0].analysis_status='described'`
- **THEN** `QameraApiClient::getProduct($link->qameraProductRef)` is called exactly once
- **AND** the row is UPDATEd to `analysis_status='described'`, `analysis_described_count=1`, `analysis_total_count=1`, `analysis_refreshed_at=NOW()`
- **AND** the returned `RefreshResult` carries the new values

#### Scenario: force=true bypasses TTL even on fresh row

- **GIVEN** a link with `analysisStatus='described'`, `analysisRefreshedAt=NOW() - 30min` (inside 3600s TTL)
- **WHEN** `refresh($link, force: true)` is called
- **THEN** the upstream call is issued regardless of TTL

#### Scenario: Upstream failure returns cached values with refresh_error set

- **GIVEN** a link with cached `analysisStatus='processing'`; the upstream throws `ServerException` after retries
- **WHEN** `refresh($link, force: true)` is called
- **THEN** the row is NOT updated (cached values stand)
- **AND** the returned `RefreshResult` carries the cached `analysisStatus`, with `refreshError` set to a sanitised string starting with `"Upstream server error (HTTP 5xx)"`
- **AND** the failure is logged at severity 2

#### Scenario: NULL analysis_refreshed_at always triggers refresh

- **GIVEN** a link with `analysisStatus=NULL`, `analysisRefreshedAt=NULL` (legacy row pre-migration)
- **WHEN** `refresh($link, force: false)` is called
- **THEN** the TTL gate treats NULL as infinitely stale and issues the upstream call

### Requirement: Aggregate reduction maps images[] to a single status enum and counts

The reduction from `ProductImageDto[]` to the four cache columns SHALL follow this deterministic algorithm:

```
total = count(images)
described = count(images where analysis_status = 'described')

if total == 0:
    analysis_status = NULL                       // no images registered upstream yet
    described_count = 0
    total_count = 0
else if any image has analysis_status = 'error' AND described == 0:
    analysis_status = 'error'
else if described == total:
    analysis_status = 'described'
else if described > 0:
    analysis_status = 'partial'                  // multi-image only; single-image never hits this
else if any image has analysis_status = 'processing':
    analysis_status = 'processing'
else:
    analysis_status = 'pending'                  // all images pending OR mix of pending+error with no described
```

`analysis_described_count` and `analysis_total_count` SHALL always be populated to reflect the upstream
counts at refresh time, regardless of which branch chose `analysis_status`.

#### Scenario: Single described image yields described

- **GIVEN** `images` is `[{analysisStatus: 'described'}]`
- **WHEN** the aggregate runs
- **THEN** the result is `(status: 'described', described: 1, total: 1)`

#### Scenario: Single processing image yields processing

- **GIVEN** `images` is `[{analysisStatus: 'processing'}]`
- **THEN** the result is `(status: 'processing', described: 0, total: 1)`

#### Scenario: Single error image with no described yields error

- **GIVEN** `images` is `[{analysisStatus: 'error'}]`
- **THEN** the result is `(status: 'error', described: 0, total: 1)`

#### Scenario: Multi-image with mixed described and processing yields partial

- **GIVEN** `images` is `[{analysisStatus: 'described'}, {analysisStatus: 'processing'}]`
- **THEN** the result is `(status: 'partial', described: 1, total: 2)`

#### Scenario: Multi-image with described and error yields partial (error ignored when at least one image described)

- **GIVEN** `images` is `[{analysisStatus: 'described'}, {analysisStatus: 'error'}]`
- **THEN** the result is `(status: 'partial', described: 1, total: 2)` — not `'error'`, because at least one image is generatable

#### Scenario: All-error multi-image yields error

- **GIVEN** `images` is `[{analysisStatus: 'error'}, {analysisStatus: 'error'}]`
- **THEN** the result is `(status: 'error', described: 0, total: 2)`

#### Scenario: Empty images[] yields NULL

- **GIVEN** `images` is `[]` (product registered upstream but no images yet)
- **THEN** the result is `(status: NULL, described: 0, total: 0)`

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

