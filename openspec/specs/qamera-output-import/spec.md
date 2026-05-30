# qamera-output-import Specification

## Purpose

Defines how completed Qamera AI job outputs (packshots accepted in review, and photo-shoot scenes) are imported back into the PrestaShop product gallery. This capability owns the `ps_qamera_imported_output` ledger, the per-job import eligibility gate (type + acceptance state + already-imported), the lazy fresh-fetch of job outputs at action time, the download-resize-append placement into `ps_image` (without disturbing existing images, cover, or applying the shop watermark), and the idempotent/partial-resume semantics. Non-image outputs (video/reels) are recorded in the ledger but not placed — a deliberate v1 scope boundary.
## Requirements
### Requirement: Imported-output ledger table records every imported output

The module SHALL create a `ps_qamera_imported_output` table that records, for each output imported into the PrestaShop product gallery, the originating job and the resulting local image. The table SHALL key uniqueness on `(qamera_job_id, output_index)` so a given output of a given job can be imported at most once. Columns SHALL include at minimum: surrogate primary key; `qamera_job_id` (CHAR(36)); `output_index` (INT UNSIGNED — the position of the output within the job's `outputs[]`); `output_type` (the upstream `JobOutput.type`, e.g. `image/jpeg`); `id_shop` and `id_product` (resolved from the job `product_ref`); `id_image` (the created `ps_image` id, NULL for outputs recorded-but-not-placed such as video); `imported_at` (DATETIME). The table SHALL be created in the installer and via an upgrade script, and dropped on uninstall.

#### Scenario: Table created on install

- **WHEN** the module is installed (or upgraded to the version introducing this change)
- **THEN** `ps_qamera_imported_output` exists with a UNIQUE key on `(qamera_job_id, output_index)`

#### Scenario: Table dropped on uninstall

- **WHEN** the module is uninstalled
- **THEN** `ps_qamera_imported_output` is dropped

### Requirement: Import action is gated per job row by type and acceptance state

The module SHALL expose a single "Download to shop" import action keyed on a `qamera_job_id`. The action SHALL be eligible only when the local job row has `status='completed'` AND at least one output of MIME type `image/*`, and additionally:

- a `job_type='photo_shoot'` job is eligible unconditionally (the photo-shoot is the post-gate final asset);
- a `job_type='packshot'` job is eligible ONLY when its review row (looked up by `qamera_job_id` in `ps_qamera_packshot_review`) has `voting='accepted'`;
- a `job_type='packshot'` job whose review row is `pending` or `rejected`, or absent, SHALL NOT be eligible;
- any job for which every image output already has a ledger row SHALL surface as already-imported, not as an eligible action.

When an ineligible import is requested, the module SHALL reject it without writing any `ps_image` and return a diagnostic indicating the reason (not completed / no image output / packshot not accepted / already imported).

#### Scenario: Photo-shoot completed job is importable

- **GIVEN** a local job row `job_type='photo_shoot'`, `status='completed'` with an `image/jpeg` output and no ledger rows
- **WHEN** import eligibility is evaluated for that job
- **THEN** the action is eligible

#### Scenario: Accepted packshot is importable

- **GIVEN** a completed `job_type='packshot'` job whose `ps_qamera_packshot_review` row has `voting='accepted'`
- **WHEN** import eligibility is evaluated
- **THEN** the action is eligible

#### Scenario: Pending packshot is not importable

- **GIVEN** a completed `job_type='packshot'` job whose review row has `voting='pending'`
- **WHEN** an import is requested for that job
- **THEN** the import is rejected with a "packshot not accepted" reason and no `ps_image` is written

#### Scenario: Already-imported job surfaces as imported

- **GIVEN** a completed image job whose every `image/*` output has a `ps_qamera_imported_output` row
- **WHEN** import eligibility is evaluated
- **THEN** the action is not eligible and the job surfaces as already-imported

### Requirement: Import fetches fresh outputs lazily at action time

On import the module SHALL call `QameraApiClient::getJob(qamera_job_id)` at action time to obtain the current `outputs[]` with freshly-signed URLs, rather than reading the stored `ps_qamera_packshot_job.output_url` mirror column (which holds only the first output and a token that may have expired). The job's `product_ref` SHALL be parsed via the existing `ProductRefParser` to resolve `(id_shop, id_product)`; a `product_ref` that does not parse or that resolves to a product not registered for this shop SHALL abort the import with a diagnostic and write nothing.

#### Scenario: Import pulls all outputs fresh

- **GIVEN** a completed photo-shoot job whose upstream `GET /jobs/{id}` returns three `image/*` outputs
- **WHEN** the operator triggers the import
- **THEN** the module fetches the job fresh and considers all three outputs (not only the single mirrored `output_url`)

#### Scenario: Unparseable product_ref aborts import

- **WHEN** the fetched job has a `product_ref` that does not match `ps:<shop>:<product>`
- **THEN** the import aborts with a diagnostic and no `ps_image` is created

### Requirement: Image outputs are written into the product gallery without disturbing existing images

For each `image/*` output without a ledger row, the module SHALL download the signed URL to a temporary file, validate it is a real image, create a new `ps_image` for the resolved product, and resize it into the base file plus every product `ImageType` derivative (mirroring `AdminImportController::copyImg()`), using the current split-directory layout (`Image::getPathForCreation()`). The new image SHALL be appended at the end of the gallery (`position = highest + 1`) and SHALL NOT be set as cover. The image SHALL be associated to the shop resolved from the job `product_ref` (`Image::associateTo`). The module SHALL NOT apply the shop watermark to the downloaded asset and SHALL NOT overwrite or delete any existing `ps_image`. On success the module SHALL insert a ledger row mapping `(qamera_job_id, output_index)` to the created `id_image`.

#### Scenario: Scene appended without stealing cover

- **GIVEN** product 42 has an operator-set cover image
- **WHEN** a photo-shoot scene output is imported for product 42
- **THEN** a new `ps_image` is created, appended at the highest position + 1, with `cover` unchanged (the operator's image stays cover)
- **AND** the base file plus all product `ImageType` derivatives are generated
- **AND** a `ps_qamera_imported_output` row maps that output to the new `id_image`

#### Scenario: Thumbnails render front-office

- **WHEN** an image output is imported
- **THEN** every `ImageType::getImagesTypes('products')` size exists on disk for the new image (no broken front-office thumbnails)

#### Scenario: Multistore association

- **GIVEN** the job `product_ref` is `ps:2:42`
- **WHEN** the image output is imported
- **THEN** the new image is associated to shop 2

### Requirement: Import is idempotent and resumes partial sets

A re-triggered import of a job whose outputs are already in the ledger SHALL be a no-op for those outputs (no duplicate `ps_image`, no error). When a job's image outputs are only partially imported (some have ledger rows, some do not — e.g. after a mid-set download failure), a re-triggered import SHALL import only the outputs that lack a ledger row. A single output whose download or resize fails SHALL NOT abort the remaining outputs of the set; the module SHALL record the successful outputs and surface a partial-failure diagnostic for the rest.

#### Scenario: Re-import is a no-op

- **GIVEN** every image output of a job already has a ledger row
- **WHEN** the import is triggered again
- **THEN** no new `ps_image` is created and the action reports already-imported

#### Scenario: Partial set resumes

- **GIVEN** a three-image job where outputs 0 and 1 have ledger rows and output 2 does not
- **WHEN** the import is triggered
- **THEN** only output 2 is downloaded and imported; outputs 0 and 1 are skipped

#### Scenario: One failed output does not abort the set

- **GIVEN** a three-image job with no ledger rows where the download of output 1 fails
- **WHEN** the import runs
- **THEN** outputs 0 and 2 are imported and ledgered, and the action surfaces a partial-failure diagnostic naming output 1

### Requirement: Non-image outputs are recorded but not placed

Outputs whose `type` is not `image/*` (e.g. video / reel outputs) SHALL be recorded in the ledger with `id_image = NULL` and SHALL NOT be written into `ps_image` or any other product surface in this version. This is a deliberate v1 scope boundary (PrestaShop has no native product-video gallery); placement of video outputs is out of scope and deferred to a follow-up.

#### Scenario: Video output is recorded, not placed

- **GIVEN** a completed job whose `outputs[]` contains a `video/mp4` output
- **WHEN** the import runs
- **THEN** a ledger row is written for that output with `id_image = NULL` and no `ps_image` is created for it

### Requirement: Output URLs are obtained fresh, not from the stale mirror

Because `GET /jobs/{id}` re-signs a 7-day URL on every call (verified upstream, recorded in design), the lazy fetch at action time inherently yields non-expired URLs; the module SHALL rely on that fresh fetch rather than a separate refresh-url round-trip. The module SHALL NOT import from the stored `ps_qamera_packshot_job.output_url` mirror, whose token may be up to 7 days old. (Should the backend later emit more than one output per job, the fetch source moves to the payload-driven refresh-url endpoint — out of scope here.)

#### Scenario: Import uses the freshly re-signed URL

- **GIVEN** a completed job whose mirrored `output_url` token is several days old
- **WHEN** the operator triggers the import
- **THEN** the module imports using the URL returned by the click-time `getJob()` fetch (freshly signed), not the stale mirror value

### Requirement: A single job output can be imported from the browse view

The module SHALL expose a per-output "Add to product gallery" import keyed on `(qamera_job_id, output_index)`, triggered from the product-detail Qamera browse accordion, reusing the existing ledger, fresh-fetch, placement, and idempotency machinery. The single-output import SHALL apply the same eligibility rules as the per-job action — the output MUST be MIME type `image/*`, a `photo_shoot` job output is eligible unconditionally, and a `packshot` job output is eligible only when its review row is `voting='accepted'` — and SHALL place only the targeted output, leaving the job's other outputs untouched.

#### Scenario: Importing one session image places only that output

- **GIVEN** a completed `photo_shoot` job with three `image/*` outputs and no ledger rows
- **WHEN** the operator triggers "Add to product gallery" on the second session image (output_index 1) in the browse view
- **THEN** the module fetches the job fresh, downloads output_index 1, appends it as a new `ps_image` at end-of-gallery without setting cover or applying the watermark
- **AND** inserts a ledger row for `(qamera_job_id, 1)` only, leaving outputs 0 and 2 unimported

#### Scenario: Per-output import is idempotent

- **GIVEN** output_index 1 of a job already has a `ps_qamera_imported_output` ledger row
- **WHEN** the operator triggers the per-output import for that same output
- **THEN** no new `ps_image` is created and the action reports already-imported

#### Scenario: Gallery-origin asset is not importable

- **WHEN** the browse view displays a product/main image or a packshot ingested from a gallery image (no backing job output)
- **THEN** no per-output import action is offered, because the asset is not a job output and originates from the gallery

#### Scenario: Pending generated packshot output is not importable

- **GIVEN** a generated packshot whose review row is `voting='pending'`
- **WHEN** a per-output import is requested for it
- **THEN** the import is rejected with a "packshot not accepted" reason and no `ps_image` is written

