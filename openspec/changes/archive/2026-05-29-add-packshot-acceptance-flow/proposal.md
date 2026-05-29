## Why

The plugin treats Generate as a single one-step submission. The canonical Qamera flow — `C:/Projects/saas-platform/docs/knowledge/packshot-acceptance-gate.md` and the merged saas-platform change `add-plugin-packshot-acceptance-gate` (PR #203) — is two-step, gated by operator approval:

1. `job_type='packshot'` generates a clean packshot from the source upload,
2. operator accepts or rejects it,
3. only an accepted packshot unlocks `job_type='photo_shoot'`.

When the upstream flag `PLUGIN_PHOTO_SHOOT_GATE_ENABLED` flips on (Phase 2 cutover), every photo_shoot from this plugin will 422 `packshot_not_approved` unless an accepted packshot exists. The plugin is not shaped to record a vote or branch the flow today.

This is the change the operator named "Phase 4.4" (the full acceptance flow), distinct from the internally-4.4-tagged `add-analysis-status-surfacing`.

## What Changes

Scope is the **flattened, single-image-per-product model the plugin runs today** — it deliberately does NOT depend on `add-multi-image-surfacing`. Acceptance is per product (its cover/primary image), keyed on the upstream `job.id`, not per-image-per-packshot. The richer per-image gallery is a later change.

- **New table `ps_qamera_packshot_review`** keyed on `qamera_job_id`: `(id PK, qamera_job_id UNIQUE, id_shop, id_product, product_ref, asset_url, voting ENUM('pending','accepted','rejected') NOT NULL DEFAULT 'pending', voting_at, generated_at)`. One row per completed `job_type='packshot'` job. (Decision: separate table, not columns on `ps_qamera_packshot_job` — clean separation of review state from job lifecycle.)
- **Submit branches by job_type** (`SubmitJobRequest` gains optional `jobType`; `Subject.packshotAssetId` becomes nullable):
  - **Stage 1 — packshot**: `job_type='packshot'`, `auto_register_packshot=true` (per upstream D5 — required), `packshot_asset_id = <qamera_asset_id source>` (the value `fix-packshot-asset-id-mismatch` makes correct).
  - **Stage 4 — photo_shoot**: `job_type='photo_shoot'`, `packshot_asset_id` **omitted** (per upstream D2 — backend resolves the latest accepted packshot per `product_ref`), `auto_register_packshot` omitted.
- **Webhook branch on `payload.job.job_type`** (reuses `job.completed` per upstream D4 — NO new event type): a completed `job_type='packshot'` inserts a `ps_qamera_packshot_review` row with `voting='pending'` and `asset_url` from `outputs[0].url`; a completed `photo_shoot` keeps the existing synced path. Depends on `fix-webhook-payload-contract` (the webhook must actually parse).
- **Accept/reject** via new `QameraApiClient::acceptJob($id)` / `rejectJob($id)` → `POST /jobs/{id}/accept|reject` (the existing endpoints; upstream cascades the vote onto `product_packshots.voting`). The plugin updates its local `ps_qamera_packshot_review.voting` on a 2xx.
- **Dedicated BO view "Packshots — review"** listing `voting='pending'` rows (thumbnail from `asset_url` + product name + ✓/✗). Voting surface only.
- **Products grid = command center, two actions**: "Generate packshot" (stage 1; gate: `qamera_asset_id` present + `analysis_status='described'`) and "Generate photo-shoot" (stage 4; enabled only when a local `ps_qamera_packshot_review` row for the product's `product_ref` has `voting='accepted'`). The grid JOINs the review table for the gate signal.
- **Strict-always client gate** for photo_shoot (the plugin cannot read the server flag, so it always requires a local accepted packshot). `422 packshot_not_approved` is a drift safety-net: detected via `ErrorEnvelope.code` and surfaced as a localized, actionable flash (`messageFor(locale)` + "accept a packshot first").

## Capabilities

### New Capabilities

- `packshot-acceptance`: lifecycle of `ps_qamera_packshot_review` (webhook insert → operator vote → gate), the dedicated review view, and the photo_shoot gate.

### Modified Capabilities

- `qamera-api-client`: `SubmitJobRequest` gains optional `jobType`; `Subject.packshotAssetId` nullable; new `acceptJob`/`rejectJob`.
- `packshot-jobs`: submitter branches packshot vs photo_shoot (job_type, auto_register, asset_id presence).
- `webhook-event-dispatch`: `JobCompletedHandler` branches on `payload.job.job_type` (`packshot` → review row; else existing path).
- `qamera-bo-ui`: dedicated "Packshots — review" view; Products grid splits Generate into two gated actions.

## Impact

- **Code**: `src/Api/Dto/{SubmitJobRequest,Subject}.php`; `src/Api/QameraApiClient.php` (accept/reject); new `src/Packshot/Acceptance/` (review entity, lookup, vote service); `JobCompletedHandler` branch; new BO controller + Twig + JS for the review view + the two grid actions; new `ps_qamera_packshot_review` migration.
- **Schema**: new `ps_qamera_packshot_review` table. **No FK to `ps_qamera_product_image`** — decoupled from multi-image; keyed on `qamera_job_id` + `product_ref` string.
- **Upstream contract**: all already shipped by PR #203 — `job_type` on submit, nullable `packshot_asset_id` on photo_shoot, `/jobs/{id}/accept|reject` cascade, `job.job_type` in the webhook. Flag `PLUGIN_PHOTO_SHOOT_GATE_ENABLED` default OFF; the plugin behaves identically OFF or ON (strict-always).
- **Depends on (all merged 2026-05-29)**: `fix-packshot-asset-id-mismatch` (#21, correct source asset_id), `fix-webhook-payload-contract` (#22, the webhook parses so stage-1 completions reach the review queue), `fix-webhook-job-error-string` (#24, failed-job error text surfaces), and `fix-packshot-catalog-registration` (#25, the submitter registers the input packshot so stage-1 jobs actually complete — without it every job 422'd `MISSING_CATALOG_ENTRY`). **Does NOT depend on** `add-multi-image-surfacing`.
- **v1 limitation**: the plugin only knows about packshots it generated + accepted locally; web-UI / legacy-accepted packshots are invisible (no `voting` on `ProductPackshotDto`). Documented; bidirectional plugin↔platform material sync is a future change (`[[project-bidirectional-materials-sync]]`).
- **Out of scope**: re-voting / vote-undo (single-shot vote v1); packshot regeneration (re-issue the packshot job); per-image gallery (`add-multi-image-surfacing`); marketplace styles browser (OQ-PS marker — keep `/plugin/presets` dropdown).
