## ADDED Requirements

### Requirement: Submitter registers the input packshot before submitting a packshot job

Before calling `QameraApiClient::submitJob()` for a `job_type='packshot'` session, the submitter SHALL, for each `SyncedProductLink` in the chunk, register the source image as an **input packshot** via `QameraApiClient::registerPackshot()`:

- `external_ref` = `'ps:<shopId>:<productId>:packshot:src'` — **stable** (NOT a random uuid), so repeated Generate clicks are idempotent (`status='created'` first, `'existing'` after) with no row churn.
- `product_ref` = the link's `ps:<shopId>:<productId>`.
- `asset_id` = the link's catalog `qamera_asset_id` (kept authoritative by the product-image-sync reconciliation).
- `source_image_ref` = omitted — the asset is already a registered, analyzed (`described`) product image, from which the backend resolves `source_image_id`.

This input packshot satisfies the upstream `resolveCatalogMetadata` requirement (`packshot_asset_id` MUST resolve to a `product_packshots` row, for packshot AND photo_shoot jobs); without it every job fails `generation_failed / PLUGIN_JOB_MISSING_CATALOG_ENTRY`. It is DISTINCT from the **output** packshot that `auto_register_packshot=true` plus the random `packshot_external_ref` register from the job result — two different external_refs, two different rows, two different roles (input source vs generated result).

If `registerPackshot()` raises an `ApiException` for a subject, the submitter SHALL NOT submit a job for that subject (it would fail catalog resolution); the failure is recorded as a chunk failure and surfaced, leaving `ps_qamera_packshot_job` unchanged for that subject.

#### Scenario: input packshot is registered before the job is submitted

- **GIVEN** a synced link for shop=1, product=42 with `qamera_asset_id='asset-uuid'`
- **WHEN** the submitter builds a `job_type='packshot'` session for it
- **THEN** it first issues `POST /packshots` with `external_ref='ps:1:42:packshot:src'`, `product_ref='ps:1:42'`, `asset_id='asset-uuid'`, and no `source_image_ref`
- **AND** only after a 2xx does it issue `POST /jobs` with `packshot_asset_id='asset-uuid'` and `auto_register_packshot=true`

#### Scenario: idempotent re-registration on a repeated Generate

- **GIVEN** the input packshot `ps:1:42:packshot:src` already exists upstream (`status='existing'`)
- **WHEN** the operator clicks Generate packshot again for product 42
- **THEN** `registerPackshot()` returns without error and the job submission proceeds normally

#### Scenario: registerPackshot failure aborts that subject's job submission

- **GIVEN** `registerPackshot()` raises `ApiException` for product 42's subject
- **THEN** no `POST /jobs` is issued for product 42, the chunk failure is recorded, and `ps_qamera_packshot_job` has no new row for that subject
