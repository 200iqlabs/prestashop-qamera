<!-- Finalized 2026-05-29: prerequisites #21/#22/#24/#25 merged; runtime contract
     confirmed by smoke (outputs[0].url = signed Supabase preview, product 31;
     job_type='packshot' completion path proven; accept/reject = 204). -->

## ADDED Requirements

### Requirement: Local packshot-review table mirrors pending acceptance state

The module SHALL maintain a `ps_qamera_packshot_review` table, one row per completed `job_type='packshot'` job, keyed on `qamera_job_id`:

- `id` — auto-increment PK
- `qamera_job_id` — CHAR(36) UNIQUE — the upstream job id (address for accept/reject)
- `id_shop`, `id_product` — resolved from the webhook `job.product_ref`
- `product_ref` — VARCHAR(200) — `ps:<shopId>:<productId>` (the gate-join key)
- `asset_url` — TEXT NULL — preview image, from the webhook `outputs[0].url`
- `voting` — ENUM(`pending`,`accepted`,`rejected`) NOT NULL DEFAULT `pending`
- `voting_at` — DATETIME NULL
- `generated_at` — DATETIME — when the packshot completion was received

The table has NO foreign key to `ps_qamera_product_image` — acceptance is per product (`product_ref`), decoupled from `add-multi-image-surfacing`.

#### Scenario: Fresh install and idempotent migration create the table
- **WHEN** the module is installed (or upgraded) 
- **THEN** `ps_qamera_packshot_review` exists with the columns above; re-running the migration is a no-op

### Requirement: Completed packshot job inserts a pending review row

When a verified `job.completed` delivery carries `payload.job.job_type === 'packshot'`, the handler SHALL upsert a `ps_qamera_packshot_review` row keyed on `payload.job.id` with `voting='pending'`, `asset_url=payload.outputs[0].url`, `product_ref`/`id_shop`/`id_product` parsed from `payload.job.product_ref`, `generated_at=NOW()`. A completed `photo_shoot` job SHALL NOT create a review row (existing synced path). This branch lives in `JobCompletedHandler` and depends on `fix-webhook-payload-contract` (the handler must parse the real `{event, job, outputs}` body).

#### Scenario: packshot completion lands in the review queue as pending
- **GIVEN** a verified `job.completed` with `job.job_type='packshot'`, `job.id='j1'`, `job.product_ref='ps:1:42'`, `outputs[0].url='https://…'`
- **THEN** a `ps_qamera_packshot_review` row exists with `qamera_job_id='j1'`, `voting='pending'`, `asset_url='https://…'`, `id_shop=1`, `id_product=42`

#### Scenario: photo_shoot completion does not create a review row
- **GIVEN** a verified `job.completed` with `job.job_type='photo_shoot'`
- **THEN** no `ps_qamera_packshot_review` row is created

### Requirement: Operator vote updates local voting via the job accept/reject endpoint

Accepting or rejecting a pending packshot SHALL call `QameraApiClient::acceptJob($qameraJobId)` / `rejectJob($qameraJobId)` (`POST /jobs/{id}/accept|reject`) and, on a 2xx, set the local row's `voting` to `accepted`/`rejected` and `voting_at=NOW()`. On a typed `ApiException`, the local row SHALL remain `pending` and the error SHALL surface to the operator.

#### Scenario: Accept cascades and updates local state
- **GIVEN** a `ps_qamera_packshot_review` row with `qamera_job_id='j1'`, `voting='pending'`
- **WHEN** the operator clicks Accept and `POST /jobs/j1/accept` returns 2xx
- **THEN** the local row has `voting='accepted'`, `voting_at` set

#### Scenario: Upstream error leaves the vote pending
- **WHEN** `acceptJob` throws `ApiException`
- **THEN** the local row stays `voting='pending'` and the operator sees the error

### Requirement: Photo-shoot is gated on a locally-accepted packshot

A product SHALL be eligible for a `job_type='photo_shoot'` submission iff it has at least one `ps_qamera_packshot_review` row for its `product_ref` with `voting='accepted'`. The gate is enforced client-side regardless of the server `PLUGIN_PHOTO_SHOOT_GATE_ENABLED` flag (which the plugin cannot read). The photo_shoot submission **omits** `packshot_asset_id` (decision: "omit + flip flag with deploy" — when the flag is ON the backend resolves the latest accepted packshot per `product_ref`).

Two upstream error codes SHALL be detected via `ErrorEnvelope.code` and surfaced as localized, actionable flashes (never a raw error):
- `packshot_not_approved` (422) — drift safety-net: upstream has no accepted packshot (e.g. rejected upstream after a local accept). Flash + "accept a packshot first".
- `invalid_input` (422) — appears when the flag is still **OFF** and `packshot_asset_id` was omitted (the backend requires it OFF). This is a deploy/flag mis-config signal, not operator error; flash a distinct "photo-shoot gate not yet enabled upstream — contact support / retry after cutover" message rather than the raw validation error. (Both the plugin gate and prod require the flag ON; smoke task 9.3 requires it too.)

#### Scenario: Accepted packshot unlocks photo-shoot
- **GIVEN** a product `ps:1:42` with a `ps_qamera_packshot_review` row `voting='accepted'`
- **THEN** its "Generate photo-shoot" action is enabled

#### Scenario: No accepted packshot keeps photo-shoot disabled
- **GIVEN** a product whose only review row is `voting='pending'` (or none)
- **THEN** "Generate photo-shoot" is disabled with a hint to generate+accept a packshot first

#### Scenario: 422 packshot_not_approved is surfaced as a friendly flash
- **WHEN** a photo_shoot submit returns 422 with `ErrorEnvelope.code='packshot_not_approved'`
- **THEN** the BO flashes `ErrorEnvelope::messageFor(locale)` plus an "accept a packshot first" hint, not a raw 422

#### Scenario: 422 invalid_input (flag OFF) is surfaced as a gate-not-enabled flash
- **GIVEN** the server `PLUGIN_PHOTO_SHOOT_GATE_ENABLED` flag is still OFF
- **WHEN** a photo_shoot submit omits `packshot_asset_id` and returns 422 with `ErrorEnvelope.code='invalid_input'`
- **THEN** the BO flashes a distinct "photo-shoot gate not yet enabled upstream" message (not a raw validation error), signalling a deploy/flag cutover issue rather than operator error
