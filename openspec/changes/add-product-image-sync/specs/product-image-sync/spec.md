## ADDED Requirements

### Requirement: Product image upload triggers upstream registration when auto-register is enabled

When the `actionWatermark` PrestaShop hook fires with parameters `id_image` and `id_product`, and `Configuration::get('QAMERAAI_AUTO_REGISTER_PRODUCTS')` evaluates truthy, and the bookkeeping row for that `(id_product, id_shop)` has `status='pending'` or `status='error'`, the module SHALL register the product upstream by uploading the primary image via the Qamera AI Plugin API. The flow is: (1) resolve the bookkeeping row, (2) resolve the primary image for the product, (3) request a presigned upload via `QameraApiClient::requestUpload()` — which returns `PresignedUploadResponse` with `uploadUrl` (target for PUT), `assetId` (opaque upstream handle), and `expiresAt`, (4) PUT the image bytes to `uploadUrl`, (5) call `QameraApiClient::registerImage` with `product_ref` from the row, `source_url` equal to the `assetId` from the presigned response (the canonical handle the upstream uses to resolve the uploaded asset), and `product_metadata` built from the row's snapshot columns, (6) persist the upstream response to the bookkeeping row. When the toggle evaluates falsy, the hook SHALL be a no-op. When the bookkeeping row already has `status='registered'` and `qamera_product_id IS NOT NULL`, the call SHALL still register the new image but SHALL OMIT `product_metadata` from the request (the product is already known upstream).

#### Scenario: First image upload on a pending product registers it upstream

- **GIVEN** a bookkeeping row for `(id_product=42, id_shop=1)` exists with `status='pending'`, `qamera_product_id=NULL`, `display_name_snapshot='Widget'`, `sku_snapshot='WDG-001'`, `description_snapshot='hello'`
- **AND** the operator has `QAMERAAI_AUTO_REGISTER_PRODUCTS=1` and uploads an image for product 42 in the BO
- **WHEN** `actionWatermark` fires with `id_product=42, id_image=99` and the upstream `POST /images` returns 201 with `{product_id: 'abc-uuid', image_id: 'img-uuid', ...}`
- **THEN** the request to `/images` carries `product_ref='ps:1:42'`, `source_url=<assetId from presigned response>`, and `product_metadata={display_name:'Widget', sku:'WDG-001', description:'hello'}`
- **AND** after the response the bookkeeping row has `status='registered'`, `qamera_product_id='abc-uuid'`, `last_synced_at=NOW()`, `last_error_message=NULL`

#### Scenario: Subsequent image upload on a registered product omits product_metadata

- **GIVEN** a bookkeeping row with `status='registered'`, `qamera_product_id='abc-uuid'`
- **WHEN** the operator uploads a second image and `actionWatermark` fires
- **THEN** the request to `/images` carries `product_ref='ps:1:42'` and `source_url=...` but does NOT include `product_metadata`
- **AND** the bookkeeping row keeps `status='registered'`, `qamera_product_id='abc-uuid'` unchanged; `last_synced_at` is bumped to NOW()

#### Scenario: Toggle off, no upstream call

- **WHEN** an administrator with `QAMERAAI_AUTO_REGISTER_PRODUCTS=0` uploads an image
- **THEN** no HTTP request is made to `qamera.ai` and the bookkeeping row's `status` is unchanged

#### Scenario: Image upload for a product with no bookkeeping row

- **GIVEN** a product whose `(id_product, id_shop)` is not present in `ps_qamera_product_link` (toggle was off when the product was created, then was turned on later)
- **WHEN** the operator uploads an image (toggle now on)
- **THEN** the hook handler SHALL NOT attempt registration (there is no snapshot to send); instead it SHALL log a debug entry "no bookkeeping row, skipping" and return. The next `actionProductSave` will create the row, and the *next* image upload will register.

### Requirement: Primary image resolution prefers cover image with a deterministic fallback chain

The module SHALL select the image to upload using `PrimaryImageResolver::resolve(int $idProduct, ?int $hintIdImage): ?Image`. The resolver SHALL try in order: (1) `Image::getCover($idProduct)` if it returns a non-empty image, (2) the `$hintIdImage` from the hook params if it points to a valid image for that product, (3) the first image returned by `Image::getImages($idProduct)` ordered by position. If all three return nothing, the resolver SHALL return null and the sync service SHALL early-return without touching the bookkeeping row's status (a missing image is not an error of the upstream sync, it is missing input).

#### Scenario: Product with a cover image

- **GIVEN** product 42 has three images and image 100 is set as cover
- **WHEN** `PrimaryImageResolver::resolve(42, 99)` is called (hint pointing to image 99, a non-cover thumbnail)
- **THEN** the resolver returns image 100 (cover wins over hint)

#### Scenario: Product without cover, hint valid

- **GIVEN** product 42 has two images and no cover is set; the operator just uploaded image 99
- **WHEN** `PrimaryImageResolver::resolve(42, 99)` is called
- **THEN** the resolver returns image 99 (hint fallback)

#### Scenario: Product with no images

- **WHEN** `PrimaryImageResolver::resolve(42, null)` is called and the product has zero images
- **THEN** the resolver returns null and the sync service skips the registration entirely without changing the bookkeeping row

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

### Requirement: PS image resize thumbnails do not trigger duplicate upstream calls

PrestaShop fires `actionWatermark` once per generated image size (cart, home, large, thickbox, etc.). The sync service SHALL deduplicate: only the first invocation per `(id_product, id_image)` within a single request lifecycle MAY trigger upstream work; subsequent invocations with the same key SHALL be no-ops. Additionally, only invocations where the resolved primary image matches `id_image` from the hook params SHALL proceed — invocations for non-cover thumbnails SHALL be skipped.

#### Scenario: Same image fires hook three times for different sizes

- **GIVEN** product 42 has cover image 100; PS generates 3 resized variants and fires `actionWatermark` three times with `id_image=100`
- **WHEN** the sync service handles all three invocations in one request
- **THEN** only one upstream `POST /assets/upload`, one PUT, and one `POST /images` are issued

#### Scenario: Non-cover thumbnail hook is skipped

- **GIVEN** product 42 has cover image 100 and a secondary image 101
- **WHEN** `actionWatermark` fires with `id_image=101` (a non-cover image upload, before cover was set)
- **AND** `PrimaryImageResolver` resolves cover image to 100, not 101
- **THEN** the sync service SHALL skip the upstream call for that invocation and SHALL NOT modify the bookkeeping row

### Requirement: Presigned upload TTL is honored

The sync service SHALL check `PresignedUploadResponse::$expiresAt` (returned by `QameraApiClient::requestUpload()`) before issuing the PUT. If `expires_at <= now()`, the service SHALL request a fresh presigned URL before uploading.

#### Scenario: Expired presigned URL forces refresh

- **WHEN** `requestUpload()` returns `expires_at = now() - 1s` (already expired, e.g. clock drift)
- **THEN** the sync service requests a fresh presigned URL and uses the new one for the PUT; the upload still succeeds
