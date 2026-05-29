# Design — add-packshot-acceptance-flow

## Context

Plugin-side surface for the two-stage packshot → photo-shoot pipeline. The backend (saas-platform PR #203) is merged with the gate behind `PLUGIN_PHOTO_SHOOT_GATE_ENABLED` (default OFF). Canonical flow and the upstream D1-D5 decisions: `C:/Projects/saas-platform/docs/knowledge/packshot-acceptance-gate.md` and `openspec/changes/add-plugin-packshot-acceptance-gate/`. Verified contract facts that anchor this design (from the saas-platform checkout, 2026-05-29):

- Submit: `job_type` optional on `SubmitJobRequestSchema` (default `photo_shoot`); `packshot_asset_id` required for `job_type='packshot'`, optional for `photo_shoot` (backend resolves latest accepted per `product_ref`); `auto_register_packshot=true` REQUIRED when `job_type='packshot'` (422 `invalid_input` otherwise).
- Vote: `POST /api/v1/plugin/jobs/{id}/accept|reject` cascades onto `product_packshots.voting` when the job is a packshot.
- Webhook: `job.completed` payload carries `job.job_type` (no new event type). For a packshot job, the preview image is `outputs[0].url` and the address for voting is `job.id`.
- Gate: photo_shoot resolves the accepted packshot from `product_ref`; missing → 422 `packshot_not_approved`.

```
                         PRODUCTS GRID (command center)
   ┌───────────────────────────────────────────────────────────────┐
   │ Product  | gate                          | action              │
   │ A        | asset_id + described          | [Generate packshot] │
   │ B        | local review.voting=accepted  | [Generate photo-shoot]
   │ C        | packshot pending review       | photo-shoot DISABLED │
   └───────────────────────────────────────────────────────────────┘
        │ stage 1                                   ▲ stage 4 (gated)
        ▼                                           │
  POST /jobs {job_type:'packshot',            POST /jobs {job_type:'photo_shoot',
    auto_register_packshot:true,                packshot_asset_id OMITTED}
    packshot_asset_id:<qamera_asset_id>}        (backend resolves latest accepted
        │                                          per product_ref)
        ▼ webhook job.completed (job.job_type='packshot')          ▲
  INSERT ps_qamera_packshot_review                                 │
    (qamera_job_id, product_ref, asset_url=outputs[0].url,         │ enables when
     voting='pending')                                            voting='accepted'
        │                                                          │
        ▼  "Packshots — review" view  ✓/✗                          │
  POST /jobs/{job_id}/accept|reject ──► update review.voting ──────┘
        (upstream cascades product_packshots.voting)
```

## Decision log

### D1: Local voting storage — RESOLVED → separate `ps_qamera_packshot_review` table

Keyed on `qamera_job_id` (accept/reject is per `job.id`). Columns: `id PK, qamera_job_id UNIQUE, id_shop, id_product, product_ref, asset_url, voting ENUM('pending','accepted','rejected') NOT NULL DEFAULT 'pending', voting_at DATETIME NULL, generated_at DATETIME`. Chosen over adding `voting`/`job_type` columns to `ps_qamera_packshot_job` for clean separation of review state from job lifecycle (operator's call). Cost accepted: a JOIN in the grid gate + a second upsert path in the webhook.

### D2: Review surface — RESOLVED → dedicated "Packshots — review" BO view

A new BO controller/tab lists `voting='pending'` rows (thumbnail from `asset_url`, product name, ✓/✗). Mirrors the canonical web UI `/content-generation/packshots`. v1 is flat (one packshot per job); a per-image/per-packshot gallery waits for `add-multi-image-surfacing`. Rejected over an inline action on the Jobs-history grid (mixes history with review queue) and over a notification-center badge (no such primitive in the plugin).

### D3: Two-stage trigger — RESOLVED → Products grid = command center, two actions

The existing "Generate" becomes "Generate packshot" (stage 1; gate `qamera_asset_id` present + `analysis_status='described'`). A separate "Generate photo-shoot" action (stage 4) is enabled only when the product's `product_ref` has a local `ps_qamera_packshot_review` row with `voting='accepted'`. The review view only votes. Rejected: triggering photo_shoot from the review view (scatters "generate" across screens) and a single auto-sequencing button (hides the two-stage cost difference).

### D4: Photo-shoot gating policy — RESOLVED → strict-always + 422 safety net

The plugin cannot read `PLUGIN_PHOTO_SHOOT_GATE_ENABLED` (server env + per-account). It therefore ALWAYS gates photo_shoot on a local accepted packshot, regardless of the server flag — behaviour is identical OFF or ON, trains the workflow, and deprecates the raw-photo photo_shoot. `422 packshot_not_approved` is a drift safety-net (e.g. packshot rejected upstream after local accept): detected via `ErrorEnvelope.code === 'packshot_not_approved'` and surfaced as a localized actionable flash (`ErrorEnvelope::messageFor(locale)` + an "accept a packshot first" hint).

### D5: Webhook discrimination — RESOLVED → branch on `payload.job.job_type` (reuse job.completed)

No new event type (upstream D4). `JobCompletedHandler` branches: `job.job_type === 'packshot'` → upsert `ps_qamera_packshot_review` (voting='pending', asset_url from `outputs[0].url`, keyed on `job.id`, product matched via parsed `product_ref`); otherwise the existing photo-shoot/synced path. **Hard dependency on `fix-webhook-payload-contract`** — until the handler parses the real `{event, job, outputs}` body, no completion reaches the review queue.

### D6: web/legacy-accepted packshots — RESOLVED (v1) → accept the local-only limitation

The gate keys on local `ps_qamera_packshot_review.voting='accepted'`. `ProductPackshotDto` (`GET /products/{ref}`) carries no `voting`, so the plugin cannot learn about packshots accepted in the web UI or grandfathered legacy rows. v1 accepts this: products accepted outside the plugin won't unlock photo_shoot inside it until a packshot is generated+accepted via the plugin. Documented in the changelog. Full bidirectional visibility is a future change — `[[project-bidirectional-materials-sync]]` (needs upstream `voting` on the read DTO).

## Deterministic from the upstream contract (no decision needed)

- `SubmitJobRequest` gains `?string $jobType` → `toPayload()` emits `job_type` when set.
- `Subject.packshotAssetId` becomes nullable; `toPayload()` omits it when null. Packshot subjects send it (the source `qamera_asset_id`); photo_shoot subjects omit it.
- Packshot submissions set `autoRegisterPackshot=true` on every subject (D5 upstream — else 422 `invalid_input`); photo_shoot submissions omit it.
- **Input-packshot registration is scoped to stage-1 only (interaction with `fix-packshot-catalog-registration`, #25).** That fix made `PackshotJobSubmitter` call `registerPackshot('ps:s:p:packshot:src', asset=qamera_asset_id)` before *every* submit (single-path submitter). When this change adds the `job_type` branch, the `registerPackshot` pre-flight MUST run **only for `job_type='packshot'`** (the input source the upstream catalog needs). A `job_type='photo_shoot'` submission omits BOTH the input-packshot registration AND `packshot_asset_id` — the backend resolves the accepted packshot per `product_ref` (flag ON). Failing to scope it would register a spurious `:src` packshot on the photo_shoot path.
- `QameraApiClient::acceptJob(string $id): void` / `rejectJob(string $id): void` → `POST /jobs/{id}/accept|reject`, which return **`204 No Content`** (verified — pure metadata). The methods return on 2xx; the caller flips the local `ps_qamera_packshot_review.voting`. A `409 job_not_completed` surfaces as the typed `ApiException`.

## Resolved after prerequisites merged + runtime confirmation (2026-05-29)

All prerequisites are merged (`#21` asset-id, `#22` webhook-contract, `#24` job.error-string, `#25` catalog-registration) and the contract is runtime-confirmed:

- `job_type` placement = request top-level — verified against `SubmitJobRequestSchema`.
- accept/reject = **`204 No Content`** (not a `JobDto`) — verified against `plugin-v1.yaml`; methods are `: void`. `409 job_not_completed` for non-completed jobs.
- **`outputs[0].url` IS the packshot preview** — PROVEN by the #25 smoke (product 31): a real `job.completed(job_type=packshot)` carried `outputs[0].url` = a signed Supabase preview (`outputs[0].type='image/jpeg'`), persisted verbatim into `ps_qamera_packshot_job.output_url`. The webhook `outputs[]` is the same shape as `GET /jobs` outputs, so the `asset_url = outputs[0].url` mapping is sound.
- `job.product_ref` shape = clean `ps:<shop>:<product>` — confirmed; the strict `ProductRefParser` (#22) accepts it.
- Spec scenarios + tasks.md finalized in this pass.

## Out of scope

Re-voting/undo, packshot regeneration, per-image gallery (`add-multi-image-surfacing`), marketplace styles browser (OQ-PS), bidirectional material sync.
