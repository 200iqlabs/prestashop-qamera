## Why

The current plugin treats Generate as a single one-step `photo_shoot` submission against a flat `packshot_asset_id`. The canonical Qamera flow — fully documented in `C:/Projects/saas-platform/docs/knowledge/packshot-acceptance-gate.md` and the saas-platform change `add-plugin-packshot-acceptance-gate` — is two-step and gated by operator approval:

1. `job_type='packshot'` produces N candidate packshots for an image,
2. operator votes per-packshot (`accepted` / `rejected`),
3. only then can a `job_type='photo_shoot'` run against the approved packshot.

When the upstream feature flag `PLUGIN_PHOTO_SHOOT_GATE_ENABLED` flips on (Phase 2 cutover, saas-platform side), every photo_shoot from this plugin will 422 with `packshot_not_approved` because no packshot has `voting='accepted'`. The plugin is not even shaped to record a vote today.

This is the change the operator explicitly named "Phase 4.4" in conversation 2026-05-28 (distinct from `add-analysis-status-surfacing` which the codebase tags 4.4 internally — operator's "4.4" is this full acceptance flow).

## What Changes

- New table `ps_qamera_packshot` mirroring upstream `PackshotDtoSchema`: `(id_packshot PK, id_product_image FK, qamera_packshot_id, voting ENUM('pending','accepted','rejected') NULL, voted_by, voted_at, generated_image_url, generation_job_id, created_at)`.
- Generate flow restructured to two steps:
  - **Step 1 — Packshot job**: operator picks image(s) + style/preset → plugin submits `job_type='packshot'` → on webhook completion, packshot rows persisted with `voting=NULL`.
  - **Step 2 — Acceptance UI**: new BO sub-tab "Packshots" (or per-product drawer — design.md decides) showing pending packshots with thumbnail preview + accept/reject buttons → plugin POSTs vote to `/packshots/{id}/vote` (or whichever endpoint the saas-platform exposes).
  - **Step 3 — Photo shoot**: only enabled once `voting='accepted'` on at least one packshot for the image → submits `job_type='photo_shoot'` with `packshot_id` (not `packshot_asset_id`) referencing the approved packshot.
- New webhook handler for `packshot.completed` event: persists candidate packshots into `ps_qamera_packshot`, surfaces them in the acceptance UI.
- New webhook handler for `packshot.voted` event (if upstream emits it) for multi-operator scenarios where the vote happens in the Qamera web UI rather than the PS plugin.
- `SyncedProductImage::canPhotoShoot()` gate: returns true iff at least one packshot on this image has `voting='accepted'`.
- **BREAKING** for `PackshotJobSubmitter`: it stops being a one-shot "submit photo_shoot" service and becomes a two-stage state machine; existing single-step Generate UI is replaced.

## Capabilities

### New Capabilities

- `packshot-acceptance`: persistence and lifecycle of candidate packshots, voting UI, and the photo_shoot gate.

### Modified Capabilities

- `packshot-jobs`: split into packshot-job submission and photo_shoot-job submission; submitter takes `packshotId` (post-vote) for photo_shoot, not raw `packshotAssetId`.
- `webhook-handler`: new `packshot.completed` and `packshot.voted` event subtypes; dispatcher routes them to the new handler.
- `webhook-event-dispatch`: registered events table extended to include `packshot.*`.
- `qamera-bo-ui`: new "Packshots" sub-tab (or per-product drawer) with thumbnail grid + vote affordances; Generate button on the products grid splits into "Generate packshots" and "Generate session" with the latter disabled until a vote lands.

## Impact

- **Code**: new `src/Packshot/Acceptance/` module (entity, lookup, vote dispatcher), new webhook handlers under `src/Webhook/Handler/`, new BO controller + Twig templates, new JS for vote interaction.
- **Schema**: new `ps_qamera_packshot` table; FK to `ps_qamera_product_image` so this change cannot ship before `add-multi-image-surfacing` lands.
- **Upstream contract dependencies**: requires `PLUGIN_PHOTO_SHOOT_GATE_ENABLED` semantics on saas-platform, the vote endpoint (URL TBD upstream), and the `packshot.completed` webhook event. Coordinate cutover with saas-platform release notes.
- **Depends on**: `fix-packshot-asset-id-mismatch` (correct asset_id flow), `add-multi-image-surfacing` (per-image addressability).
- **Out of scope**: re-voting / vote-undo (single-shot vote in v1), packshot regeneration (operator deletes + re-issues packshot job), inline image generation preview (operator opens Qamera web UI for full preview).
- **Out of scope (deliberate)**: surfacing the existing Qamera web UI marketplace styles browser inside PS — v1 keeps the `/plugin/presets` dropdown (OQ-PS marker).
