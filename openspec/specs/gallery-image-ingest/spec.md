# gallery-image-ingest Specification

## Purpose
TBD - created by archiving change add-gallery-image-ingest. Update Purpose after archive.
## Requirements
### Requirement: Operator selects store gallery images to ingest

The module SHALL present, on the product-detail Qamera tab, the product's PrestaShop gallery images for multi-selection, with a source toggle between "Select from store gallery" and "Upload new".

#### Scenario: Gallery grid lists product images

- **WHEN** the operator opens the Qamera tab on a product that has PrestaShop gallery images
- **THEN** the module displays each gallery image as a selectable thumbnail
- **AND** allows selecting one or more images

#### Scenario: No gallery images present

- **WHEN** the product has no PrestaShop gallery images
- **THEN** the module shows an empty-state message and the "Upload new" path remains available

### Requirement: Ingest a gallery image as a product image (Flow A)

When the operator chooses "Add as product" for a selected gallery image, the module SHALL upload the image bytes server-side via a presigned URL and register it as a product image, using a stable, collision-free `external_ref`.

#### Scenario: Add as product succeeds

- **WHEN** the operator triggers "Add as product" on a selected gallery image
- **THEN** the module fetches the image bytes server-side and PUTs them to a presigned upload URL
- **AND** calls register-image with `external_ref` `ps:<shop>:<prod>:image:<psImageId>`, `product_ref` `ps:<shop>:<prod>`, and the returned `asset_id`
- **AND** reports per-item status advancing through uploading → registering → analyzing → ready

#### Scenario: Re-ingesting the same image is idempotent

- **WHEN** the operator adds a gallery image that was already registered under the same `external_ref`
- **THEN** the module treats the upstream `status:'existing'` response as success
- **AND** does not create a duplicate

### Requirement: Ingest a gallery image as a packshot (image-then-packshot)

When the operator chooses "Add as packshot" for a selected gallery image, the module SHALL first register the image (idempotently), then register a packshot referencing that image via `source_image_ref`, so the resulting packshot always has a non-null `source_image_id`.

#### Scenario: Add as packshot links to its source image

- **WHEN** the operator triggers "Add as packshot" on a selected gallery image
- **THEN** the module registers the image first with `external_ref` `ps:<shop>:<prod>:image:<psImageId>`
- **AND** then registers a packshot with `external_ref` `ps:<shop>:<prod>:pack:<psImageId>`, `product_ref` `ps:<shop>:<prod>`, the uploaded `asset_id`, and `source_image_ref` equal to the image's `external_ref`
- **AND** the packshot lands accepted with a non-null source image

#### Scenario: Image-then-packshot ordering is enforced

- **WHEN** the packshot registration would run before its source image is registered
- **THEN** the module registers (or confirms) the image first
- **AND** never submits a packshot whose `source_image_ref` does not yet resolve

### Requirement: Bytes are uploaded server-side

The module SHALL fetch gallery image bytes within the PrestaShop server process and deliver them via presigned PUT, never via the browser, and SHALL reject files exceeding the upload size limit.

#### Scenario: Server-side presigned upload

- **WHEN** an image is ingested
- **THEN** the module requests a presigned upload, PUTs the raw bytes with the matching content type, and references the returned `asset_id`
- **AND** no image bytes are transferred through the operator's browser

#### Scenario: Oversize file rejected

- **WHEN** a selected image exceeds the maximum upload size
- **THEN** the module reports a per-item error and does not attempt the upload

### Requirement: external_ref namespace is consistent and collision-free

The module SHALL mint `external_ref` values that are stable per `(installation, ref)` and consistent with the hook-sync code path, so that a packshot's `source_image_ref` always matches its source image's stored `external_ref`.

#### Scenario: Image and packshot refs share the product-scoped scheme

- **WHEN** the module builds refs for a gallery image and its packshot
- **THEN** the image ref is `ps:<shop>:<prod>:image:<psImageId>` and the packshot ref is `ps:<shop>:<prod>:pack:<psImageId>`
- **AND** the same builder is used by hook-sync registration so a packshot's `source_image_ref` resolves to the existing image

### Requirement: Write scope is verified before ingest

The module SHALL verify the installation holds the `plugin.catalog:write` scope before exposing ingest actions, and SHALL block ingest with a clear message when the scope is absent or a `forbidden` response is returned.

#### Scenario: Missing write scope blocks ingest

- **WHEN** the installation lacks `plugin.catalog:write`
- **THEN** the module disables the "Add as product" / "Add as packshot" actions and explains the missing scope

#### Scenario: Forbidden at call time

- **WHEN** an ingest call returns HTTP 403 `forbidden`
- **THEN** the module surfaces a scope error for that item and does not retry

### Requirement: Ingest errors map to operator-facing UX

The module SHALL map each upstream error code to a per-item outcome: `invalid_input`, `unauthorized`, `forbidden`, `not_found`, and `source_asset_unavailable` are non-retryable and shown as item errors; `rate_limit_exceeded` and `internal_error` are retried with backoff using the same `external_ref`.

#### Scenario: Non-retryable error shown per item

- **WHEN** an ingest call returns `invalid_input`, `not_found`, or `source_asset_unavailable`
- **THEN** the module marks that item failed with the mapped message and continues with the remaining items

#### Scenario: Retryable error backs off and retries

- **WHEN** an ingest call returns `rate_limit_exceeded` or `internal_error`
- **THEN** the module retries with backoff reusing the same `external_ref`
- **AND** relies on idempotency to avoid duplicates

