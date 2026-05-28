## ADDED Requirements

### Requirement: Local job-state table mirrors upstream submissions

The module SHALL maintain a `ps_qamera_packshot_job` table that mirrors the state of every job submitted to
the Qamera AI API via the BO UI. One row per `qamera_job_id` returned in a `SubmitJobResponse`. Rows live
independently of `ps_qamera_packshot_link` so failed jobs (which produce no packshot) and pending jobs
(submitted but no webhook yet) remain visible to the operator.

Required columns (logical shape — DDL details belong in migration):

- `id_qamera_packshot_job` — auto-increment surrogate primary key
- `qamera_job_id` — CHAR(36), UNIQUE — upstream job UUID
- `qamera_order_id` — CHAR(36) — upstream order UUID returned by `POST /jobs`
- `id_qamera_product_link` — FK to `ps_qamera_product_link.id_qamera_product_link`
- `id_shop`, `id_product` — denormalised for index-friendly grid queries
- `packshot_external_ref` — VARCHAR(100), UNIQUE — `ps:<shopId>:<productId>:packshot:<client-uuid>`
- `status` — ENUM(`pending`, `in_progress`, `completed`, `failed`, `cancelled`), default `pending`
- `output_url` — TEXT NULL — populated on `job.completed`
- `output_url_expires_at` — DATETIME NULL — populated when payload carries it
- `last_error_message` — TEXT NULL — populated on `job.failed`
- `ai_model`, `aspect_ratio`, `images_count` — denormalised submission inputs
- `session_config_json` — JSON column with the full session_config snapshot for audit/re-submit
- `submitted_at`, `last_synced_at` — DATETIME timestamps

Required indexes: PK on `id_qamera_packshot_job`; UNIQUE on `qamera_job_id`; UNIQUE on `packshot_external_ref`;
non-unique on `(id_shop, id_product)`; non-unique on `(status, submitted_at)`.

#### Scenario: Submitting a job inserts one local row per returned job_id

- **GIVEN** the operator submits a form with `images_count=3` for one product
- **AND** upstream returns `order_id='ord-123'`, `subjects[0].job_ids=['j1','j2','j3']`
- **WHEN** the submitter persists the response
- **THEN** three rows exist in `ps_qamera_packshot_job` with `qamera_job_id` ∈ {`j1`,`j2`,`j3`}, all
  carrying `qamera_order_id='ord-123'`, the same `id_qamera_product_link`, `status='pending'`,
  `submitted_at=<now>`, `output_url=NULL`

#### Scenario: Bulk-action with 5 products and images_count=2 inserts 10 rows in a single session

- **GIVEN** the operator selects 5 synced products and submits the form with `images_count=2`
- **AND** upstream returns one `order_id` and five subjects, each with 2 `job_ids`
- **WHEN** the submitter persists the response
- **THEN** exactly 10 rows are inserted, all sharing the same `qamera_order_id`, each FK'd to the right
  `id_qamera_product_link`

### Requirement: Submitter generates packshot_external_ref client-side and sets auto_register_packshot=true

The submitter SHALL generate a UUID v4 per subject and embed it as
`packshot_external_ref = 'ps:<shopId>:<productId>:packshot:<uuid>'` in the `Subject` DTO before calling
`QameraApiClient::submitJob()`. The `Subject.auto_register_packshot` field SHALL be `true` for every
submission. The generated ref is stored on the local job row for three-way reconciliation with the upstream
job and the upstream `product_packshots` row.

#### Scenario: External ref shape matches the documented format

- **WHEN** the submitter builds a Subject for shop=1, product=42
- **THEN** the resulting `packshot_external_ref` matches the regex `^ps:1:42:packshot:[0-9a-f-]{36}$`
- **AND** `auto_register_packshot=true` in the outgoing JSON body

### Requirement: Submitter writes local rows only after upstream returns 2xx

The submitter SHALL NOT write to `ps_qamera_packshot_job` before `QameraApiClient::submitJob()` returns
successfully. Upstream errors (any non-2xx) propagate as the corresponding typed exception
(`ApiValidationException`, `AuthException`, `RateLimitException`, `ServerException`, `TransportException`)
without DB side effects.

If the local insert fails after a successful upstream call, the submitter SHALL log an ERROR-level message
with `qamera_order_id` and the job_ids that failed to persist, and propagate the DB exception. Eventual
consistency is provided by the webhook handler (see `webhook-handler` spec) which upserts the row if missing.

#### Scenario: 503 from upstream leaves no local rows

- **GIVEN** `submitJob()` raises `ServerException` (HTTP 503)
- **WHEN** the submitter handles the failure
- **THEN** `ps_qamera_packshot_job` is unchanged
- **AND** the exception propagates to the controller for flash-error rendering

#### Scenario: 422 validation error leaves no local rows

- **GIVEN** `submitJob()` raises `ApiValidationException` with field-level errors
- **WHEN** the submitter handles the failure
- **THEN** `ps_qamera_packshot_job` is unchanged
- **AND** the exception is propagated so the form can re-render with errors

### Requirement: Submitter chunks bulk submissions over 100 subjects into multiple sessions

When the BO bulk action selects more than 100 products, the submitter SHALL split the work into chunks of at
most 100 subjects, each chunk sent as a separate `POST /jobs` call with its own `Idempotency-Key`. Chunks
run sequentially. The result returned to the controller SHALL report total submitted sessions, total job_ids
persisted, and any chunk-level failures.

#### Scenario: 247 selected products split into 3 sessions

- **GIVEN** the operator triggers bulk-generate on 247 synced products
- **WHEN** the submitter processes the request
- **THEN** exactly 3 `POST /jobs` calls are made (chunks of 100, 100, 47)
- **AND** each call carries a distinct `Idempotency-Key`
- **AND** on full success, 247 rows are inserted in `ps_qamera_packshot_job`

#### Scenario: Partial chunk failure reports session-level outcome

- **GIVEN** chunks #1 and #3 succeed but chunk #2 fails with ServerException
- **WHEN** the submitter returns to the controller
- **THEN** the result indicates 2 successful sessions, 1 failed session, and the persisted-job count
  reflects only chunks #1 and #3

### Requirement: Cost calculator pre-flights credit usage from cached pricing

The module SHALL expose a `CostCalculator` service that, given a submitted form's `(ai_model, images_count,
subjects.length)`, returns the total credit cost computed as
`unit_cost(ai_model) × images_count × subjects.length`, where `unit_cost` is read from the cached
`/pricing` response's `PricingEntry.creditCost` matching `(jobType='packshot', provider, model)`.

When no matching pricing entry is found, the calculator SHALL return `null` and the UI SHALL display
"unavailable" rather than zero — zero would mislead the operator.

#### Scenario: Single product, 4 images, model at 5 credits/image

- **GIVEN** `/pricing` returns `{provider:'openai', model:'gpt-image-1', creditCost:5}`
- **WHEN** the form has `ai_model='openai/gpt-image-1'`, `images_count=4`, 1 subject
- **THEN** `CostCalculator::estimate()` returns `20`

#### Scenario: Bulk action, 3 products, 2 images, model at 7 credits/image

- **WHEN** the same model is selected with 3 subjects and `images_count=2`
- **THEN** the calculator returns `42`

#### Scenario: Model not found in pricing returns null

- **GIVEN** `/pricing` returns no entry matching the selected model
- **WHEN** the calculator runs
- **THEN** it returns `null`

### Requirement: Repository exposes typed read operations for the BO UI

A `PackshotJobRepository` SHALL expose at least:

- `findByJobId(string $jobId): ?PackshotJobRow`
- `findByExternalRef(string $ref): ?PackshotJobRow`
- `listForGrid(JobsGridFilters $filters): array` — status filter, cursor pagination, joined with
  `ps_product_lang` for the localised product name
- `insertBatch(array $rows): void` — single statement, ON DUPLICATE KEY UPDATE on `qamera_job_id` so retries
  of the same upstream order are idempotent
- `upsertFromWebhook(PackshotJobWebhookUpdate $update): void` — used by `PackshotJobUpdater`

All methods SHALL use prepared statements via `Db::getInstance()->execute()` / `executeS()`. Raw string
concatenation into SQL is forbidden.

#### Scenario: insertBatch is idempotent under retry

- **GIVEN** `insertBatch` is called twice with the same set of rows (e.g. controller retry after a
  transient failure)
- **THEN** the table contains exactly one row per `qamera_job_id`, with the second call updating
  `submitted_at` / `session_config_json` and leaving status untouched if already terminal
