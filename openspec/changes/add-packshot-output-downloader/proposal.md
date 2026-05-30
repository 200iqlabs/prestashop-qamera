## Why

Today the value loop is open: an operator generates a packshot, accepts it (Phase 4.4), runs a photo-shoot — and then has to leave PrestaShop, open the Qamera panel, and manually download the finished images to re-upload them onto the product. The backend already hands the plugin everything needed to close this loop (`job.completed` carries `job_type` + `outputs[]`; `GET /jobs/{id}` returns the same outputs on demand; `GET /jobs/{id}/refresh-url` re-signs an expired download link), but no code path imports those outputs back into the product gallery. This change automates the last step: completed image outputs land in the PrestaShop product gallery with one click.

## What Changes

- **New "Download to shop" action in the Jobs history grid.** A single entry point gates per row: `job_type='photo_shoot'` completed jobs are importable; `job_type='packshot'` completed jobs are importable **only when their review row is `voting='accepted'`** (looked up by `qamera_job_id`); pending/rejected packshots and already-imported rows show no active button. The Packshots review view is unchanged — it stays a pure accept/reject gate (accepted rows already leave its `listPending` queue, so the action cannot live there).
- **Lazy, fresh fetch on click.** The import calls `GET /jobs/{id}` at click time to obtain all outputs with freshly-signed URLs, rather than reading the single stale `output_url` mirror column. Each image output is downloaded and written into `ps_image` mirroring `AdminImportController::copyImg()` (base file + resize into every `ImageType`, new split-dir layout via `getPathForCreation()`, `associateTo([shopId])`, **no** watermark). Images are **appended** to the gallery and never set as cover.
- **Import-once bookkeeping.** A new `ps_qamera_imported_output` ledger keyed on `(qamera_job_id, output_index)` records each imported output → `id_image`. Re-clicking is an idempotent no-op (the row shows "imported ✓ → id_image N"); a partially-imported set resumes by importing only outputs without a ledger row. There is no silent overwrite of an existing `ps_image`.
- **Feedback-loop guard.** `ProductImageSyncService` consults the ledger and skips any `id_image` of Qamera origin when resolving the primary image to upload, so an imported scene can never be re-uploaded to Qamera as a source image (belt-and-suspenders over the existing D2 registered-with-asset no-op).
- **No new API client method.** Design-phase verification against `saas-platform` confirmed `GET /jobs/{id}` re-signs a fresh 7-day URL on every call, so the click-time `getJob()` fetch is always fresh — the `refresh-url` method (which is upstream a **POST**, payload-driven, multi-output) is **deferred** to a future multi-output change, not needed here.
- **Out of scope (v1):** reel/video outputs are recorded in the ledger but NOT placed anywhere (PrestaShop has no native product-video gallery) — placement is a v2 follow-up, flagged like the existing `OQ-PS*` out-of-scope markers. No separate "Qamera media library", no auto-import inside the webhook ACK, no front-office surface beyond the default PS product-image flow.

## Capabilities

### New Capabilities

- `qamera-output-import`: One-click import of a completed job's image outputs into the PrestaShop product gallery — lazy fresh fetch of outputs, per-row gating (photo_shoot, or accepted packshot), `copyImg`-style write into `ps_image` with full thumbnail regeneration, the `ps_qamera_imported_output` ledger (dedup / import-once / partial-retry / origin marker), and recording-but-not-placing video outputs.

### Modified Capabilities

- `product-image-sync`: the primary-image resolution / upload path gains an exclusion — an `id_image` recorded in the imported-output ledger (Qamera origin) is never selected for upstream re-upload.
- `qamera-bo-ui`: the Jobs history grid gains the per-row "Download to shop" action with state-based gating and an "imported ✓" terminal state; coordinates on the same `jobs_history.*` assets as `add-jobs-history-refresh`.

## Impact

- **New code:** `src/Packshot/Output/OutputImporter.php`, `src/Packshot/Output/ImportedOutputRepository.php` (+ row VO), `src/Controller/Admin/OutputImportController.php`, a `config/routes.yml` route, `config/services.yml` wiring.
- **Schema:** new `ps_qamera_imported_output` table + `upgrade/upgrade-1.8.0.php`; module version bump 1.7.0 → 1.8.0.
- **Edited code:** `views/templates/admin/jobs_history.html.twig` + `views/js/jobs_history.js` (button + gating — **merge-conflict surface with `add-jobs-history-refresh`**; whichever merges first, the other rebases); `src/Sync/ProductImageSyncService.php` (ledger exclusion).
- **Upstream contract:** uses already-shipped `GET /jobs/{id}` only (verified to re-sign fresh per call). No new endpoints, no API-client change. No live calls in CI — Guzzle `MockHandler` for units, operator-driven smoke for the real download.
- **Sequencing:** independent of the photo-shoot gate flag flip; can proceed in parallel.
- **PS-version note:** `Image`, `ImageManager::resize`, `ImageType::getImagesTypes`, `getPathForCreation()` are stable across PS 8.0–9.x; no PS-9-only API introduced.
