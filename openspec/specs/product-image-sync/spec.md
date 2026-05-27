# product-image-sync Specification

## Purpose

Defines how PrestaShop product images are pushed upstream to the Qamera AI Plugin API when the `actionWatermark` hook fires. Builds on the Phase-2 `product-sync-bookkeeping` rows: this capability owns the state transitions (`pending`/`error` → `registered`), the presigned-upload + PUT + `registerImage` orchestration, the primary-image resolution chain, in-request dedup, and the sanitized error mapping that lands on the bookkeeping row's `last_error_message`. The hook handler never lets an upstream failure bubble — back-office product saves stay successful regardless of upstream state, mirroring the Phase-2 swallow-throw contract.

## Requirements

### Requirement: Product image upload triggers upstream registration when auto-register is enabled

When the `actionWatermark` PrestaShop hook fires with parameters `id_image` and `id_product`, and `Configuration::get('QAMERAAI_AUTO_REGISTER_PRODUCTS')` evaluates truthy, and the bookkeeping row for that `(id_product, id_shop)` has `status='pending'` or `status='error'`, the module SHALL register the product upstream by uploading the primary image via the Qamera AI Plugin API. The flow is: (1) resolve the bookkeeping row, (2) resolve the primary image for the product, (3) request a presigned upload via `QameraApiClient::requestUpload()` — which returns `PresignedUploadResponse` with `uploadUrl` (target for PUT), `assetId` (opaque upstream handle), and `expiresAt`, (4) PUT the image bytes to `uploadUrl`, (5) call `QameraApiClient::registerImage` with `product_ref` from the row, `asset_id` equal to the `assetId` from the presigned response (the canonical handle the upstream uses to resolve the uploaded asset), `external_ref` deterministically derived from `<product_ref>:image:<id_image>` (caller-supplied stable id for upstream idempotency), and `product_metadata` built from the row's snapshot columns, (6) persist the upstream response to the bookkeeping row. When the toggle evaluates falsy, the hook SHALL be a no-op. When the bookkeeping row already has `status='registered'` and `qamera_product_id IS NOT NULL`, the call SHALL still register the new image but SHALL OMIT `product_metadata` from the request (the product is already known upstream).

#### Scenario: First image upload on a pending product registers it upstream

- **GIVEN** a bookkeeping row for `(id_product=42, id_shop=1)` exists with `status='pending'`, `qamera_product_id=NULL`, `display_name_snapshot='Widget'`, `sku_snapshot='WDG-001'`, `description_snapshot='hello'`
- **AND** the operator has `QAMERAAI_AUTO_REGISTER_PRODUCTS=1` and uploads an image for product 42 in the BO
- **WHEN** `actionWatermark` fires with `id_product=42, id_image=99` and the upstream `POST /images` returns 201 with `{product_id: 'abc-uuid', image_id: 'img-uuid', ...}`
- **THEN** the request to `/images` carries `external_ref='ps:1:42:image:99'`, `product_ref='ps:1:42'`, `asset_id=<assetId from presigned response>`, and `product_metadata={display_name:'Widget', sku:'WDG-001', description:'hello'}`
- **AND** after the response the bookkeeping row has `status='registered'`, `qamera_product_id='abc-uuid'`, `last_synced_at=NOW()`, `last_error_message=NULL`

#### Scenario: Subsequent image upload on a registered product omits product_metadata

- **GIVEN** a bookkeeping row with `status='registered'`, `qamera_product_id='abc-uuid'`
- **WHEN** the operator uploads a second image and `actionWatermark` fires
- **THEN** the request to `/images` carries `product_ref='ps:1:42'`, `asset_id=...`, and a per-image `external_ref` but does NOT include `product_metadata`
- **AND** the bookkeeping row keeps `status='registered'`, `qamera_product_id='abc-uuid'` unchanged; `last_synced_at` is bumped to NOW()

#### Scenario: Toggle off, no upstream call

- **WHEN** an administrator with `QAMERAAI_AUTO_REGISTER_PRODUCTS=0` uploads an image
- **THEN** no HTTP request is made to `qamera.ai` and the bookkeeping row's `status` is unchanged

#### Scenario: Image upload for a product with no bookkeeping row

- **GIVEN** a product whose `(id_product, id_shop)` is not present in `ps_qamera_product_link` (toggle was off when the product was created, then was turned on later)
- **WHEN** the operator uploads an image (toggle now on)
- **THEN** the hook handler SHALL NOT attempt registration (there is no snapshot to send); instead it SHALL emit a single `PrestaShopLogger::addLog` entry at severity 1 (info — diagnostic, not a warning) with `object_type='QameraAiModule'`, `object_id=<idProduct>`, `allowDuplicate=true`, and a message of the form `'[QameraAi] no bookkeeping row for id_product=<n>, id_shop=<n>; skipping image sync. Next actionProductSave will create the row.'` — same shape and channel as the Phase-2 swallow-throw log entries. Then return without further work.

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
