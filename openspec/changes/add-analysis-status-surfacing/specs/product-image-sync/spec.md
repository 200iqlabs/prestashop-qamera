## ADDED Requirements

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

#### Scenario: Multi-image with described and error yields described (error ignored when any described)

- **GIVEN** `images` is `[{analysisStatus: 'described'}, {analysisStatus: 'error'}]`
- **THEN** the result is `(status: 'partial', described: 1, total: 2)` — not `'error'`, because at least one image is generatable

#### Scenario: All-error multi-image yields error

- **GIVEN** `images` is `[{analysisStatus: 'error'}, {analysisStatus: 'error'}]`
- **THEN** the result is `(status: 'error', described: 0, total: 2)`

#### Scenario: Empty images[] yields NULL

- **GIVEN** `images` is `[]` (product registered upstream but no images yet)
- **THEN** the result is `(status: NULL, described: 0, total: 0)`

### Requirement: Per-link Generate-readiness gate requires described analysis

`SyncedProductLink::canGenerate()` SHALL return true iff `$this->qameraImageId !== null AND $this->analysisStatus === 'described'`. Any other combination (NULL image, NULL analysis_status, or any non-`described` analysis status) SHALL return false.

`SyncedProductLink` SHALL expose a method `getDisabledHint(): ?string` returning the operator-facing hint string for the disabled-button state, with the mapping defined by the qamera-bo-ui spec's "Products grid lists synced rows" requirement. The mapping is owned by THIS link's analysis state — the BO controller only reads it, it does not assemble it.

`SyncedProductLinkLookup::listForGrid()` SHALL include the four new columns in the SELECT and populate them on the constructed `SyncedProductLink` instances. `SyncedProductLinkLookup::loadByProductIds()` SHALL also include the new columns so the bulk-select path uses the same data.

#### Scenario: Image present and described enables generate

- **GIVEN** a `SyncedProductLink` with `qameraImageId='img-uuid'` and `analysisStatus='described'`
- **WHEN** `canGenerate()` is called
- **THEN** the result is `true`

#### Scenario: Image present but processing blocks generate

- **GIVEN** a `SyncedProductLink` with `qameraImageId='img-uuid'` and `analysisStatus='processing'`
- **WHEN** `canGenerate()` is called
- **THEN** the result is `false`
- **AND** `getDisabledHint()` returns "Image is being analysed…" (or its translated variant)

#### Scenario: Image present but NULL analysis_status blocks generate

- **GIVEN** a `SyncedProductLink` with `qameraImageId='img-uuid'` and `analysisStatus=NULL` (legacy pre-migration)
- **WHEN** `canGenerate()` is called
- **THEN** the result is `false`
- **AND** `getDisabledHint()` returns "Awaiting analysis status — refresh"

#### Scenario: Image present but error blocks generate with re-sync hint

- **GIVEN** a `SyncedProductLink` with `qameraImageId='img-uuid'` and `analysisStatus='error'`
- **THEN** `canGenerate()` returns `false`
- **AND** `getDisabledHint()` returns "Image analysis failed — re-sync product"

#### Scenario: Image absent always blocks generate regardless of analysis_status

- **GIVEN** a `SyncedProductLink` with `qameraImageId=NULL` and (impossibly) `analysisStatus='described'`
- **THEN** `canGenerate()` returns `false`
- **AND** `getDisabledHint()` returns "Sync this product first" (qamera_image_id takes precedence)
