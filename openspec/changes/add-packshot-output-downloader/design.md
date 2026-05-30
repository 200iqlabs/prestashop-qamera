## Context

Phase 4.4 (`add-packshot-acceptance-flow`) closed the *approval* half of the loop: a completed packshot lands in a review queue, the operator accepts, and a photo-shoot is submitted. The *return* half is still manual — the operator leaves PrestaShop, opens the Qamera panel, downloads the finished image, and re-uploads it onto the product. This change automates that last step with a one-click import into the native `ps_image` gallery.

The whole existing `src/Sync/` tree pushes PS → Qamera (upload direction). There is no PS image-*creation* code anywhere in the module today (no `new Image()`, no `ImageManager::resize`, no `copyImg`). The download direction is net-new in this module, though well-trodden in PS core (`AdminImportController::copyImg()`).

The webhook `JobCompletedHandler` already mirrors every `job.completed` into `ps_qamera_packshot_job` (both `packshot` and `photo_shoot` types) and branches packshot completions into the review queue. The Jobs history grid reads that mirror, so it already contains a row for every completed job — the natural single home for the import action.

**Upstream contract — verified against `saas-platform` (2026-05-30):**

- `GET /api/v1/plugin/jobs/{id}` (`jobs/[id]/route.ts`) projects outputs via `projectJobOutputs` (`_lib/server/job-outputs.ts`), which calls `createSignedUrl(output_path, 7d)` **on every request** → a brand-new 7-day signed URL each call. **`getJob()` at click time always returns a freshly-signed URL.**
- `projectJobOutputs` signs the single `cg_jobs.output_path` → `GET /jobs/{id}` returns **exactly one output**. The backend is one-output-per-job today; `JobDto.outputs` is an array for forward-compat but currently holds ≤1 element.
- `refresh-url` is **`POST /api/v1/plugin/jobs/{id}/refresh-url`** (NOT GET as the brief assumed). It re-signs from the latest `webhook_deliveries.payload.outputs[]` (per-entry `_storage_path`/`_bucket`), so it is the only endpoint that can return a *multi*-output set fresh; it falls back to `projectJobOutputs` (single) for legacy deliveries. Returns `{job_id, outputs[], expires_at}`; `410` when the underlying object is purged, `job_not_completed` when not completed.
- Signed-URL TTL is **7 days** (`SIGNED_URL_TTL_SECONDS`).

## Goals / Non-Goals

**Goals:**

- One-click import of a completed job's image output(s) into the product's `ps_image` gallery, with full `ImageType` thumbnail regeneration, from a single entry point (Jobs history).
- Correct per-row gating: photo-shoot completions importable unconditionally; packshot completions importable only when accepted; already-imported rows terminal.
- Idempotent, import-once bookkeeping that doubles as a partial-retry cursor and a Qamera-origin marker for the sync-loop guard.
- Never disturb existing gallery images (append, never cover, never overwrite/delete, no watermark).

**Non-Goals:**

- Video/reel placement — recorded in the ledger (`id_image=NULL`), not placed anywhere (no native PS product-video gallery). Deferred to v2, flagged like the `OQ-PS*` markers.
- Auto-import inside the webhook ACK (heavy resize work would risk an ACK timeout → Qamera retry; and there is no module cron). Import is operator-triggered.
- A separate "Qamera media library" surface; front-office display beyond the default PS image flow.
- `refreshJobUrl` API method — **deferred** (see D5 below).
- Re-import / replace of an already-imported output (import-once; a future force-replace is out of scope).

## Decisions

### D1 — Destination: native `ps_image` gallery
Matches the existing `OQ-PS*` marker ("Front-office display of generated assets — default PS product images flow handles it"). No new media-library surface.

### D2 — Trigger: manual button, lazy fresh fetch
Operator clicks "Download to shop"; the importer calls `getJob()` at that moment. Rationale: keeps the heavy `ImageManager::resize` fan-out inside an operator-waiting AJAX request (not the webhook ACK), and `getJob` re-signs fresh so there is no stale-URL problem.

### D3 — Output types: images now, video recorded-only
`image/*` outputs → gallery. Non-image outputs → ledger row with `id_image=NULL`, no placement. Branch on `JobOutput.type`.

### D4 — Idempotency via the `ps_qamera_imported_output` ledger
One table keyed `UNIQUE(qamera_job_id, output_index)` serves three roles: dedup (import-once), partial-retry cursor (import only outputs lacking a row), and origin marker (sync layer excludes these `id_image`). Columns: `id` PK, `qamera_job_id CHAR(36)`, `output_index INT UNSIGNED`, `output_type VARCHAR`, `id_shop`, `id_product`, `id_image INT UNSIGNED NULL`, `imported_at DATETIME`. No FK to `ps_image` (PS core tables, and the image may be deleted by the operator — a dangling ledger row is acceptable and still records "this job's output N was imported"). Created in `Installer` + `upgrade/upgrade-1.8.0.php`; dropped on uninstall. Module 1.7.0 → 1.8.0.

### D5 — Fetch source: `getJob()` only; `refreshJobUrl` deferred — RESOLVED by upstream verification
Because `GET /jobs/{id}` re-signs a fresh 7-day URL on every call AND today returns the single `output_path` output, `getJob()` at click time is sufficient and always fresh. The conditional `refreshJobUrl` requirement (qamera-api-client spec) and the "expired URL → refresh" requirement (qamera-output-import spec) are **satisfied vacuously / deferred** per their own written conditions — no `refreshJobUrl` method is built in v1.

**Forward-compat note:** the day the backend emits >1 output per job, `getJob` (single `output_path`) will under-report; the importer must then switch its fetch source to **`POST /jobs/{id}/refresh-url`** (payload-driven, multi-output). The ledger's `output_index` key already accommodates this; today every import uses `output_index=0`. This is the genuine future purpose of `refreshJobUrl`, not URL expiry.

### D6 — Gallery placement: append, never cover
`position = Image::getHighestPosition($idProduct) + 1`; do not touch `cover`. UX: the operator's real product photo stays the hero; they promote a scene manually if desired. This is also the primary (cheap) loop defense — an appended non-cover image is not the resolver's "primary".

### Import mechanics (mirror `AdminImportController::copyImg`)
For each `image/*` output without a ledger row:
1. `$image = new Image(); $image->id_product = $idProduct; $image->position = highest+1; $image->add();`
2. `$image->associateTo([$idShop])` (shop from parsed `product_ref`).
3. Download signed URL → temp file (`Tools::copy` / Guzzle), validate it is a real image (`ImageManager::isRealImage` / `getDefaultLanguage`-style checks), reject non-images.
4. Resize base: `ImageManager::resize($tmp, $image->getPathForCreation().'.jpg')`; then loop `ImageType::getImagesTypes('products')` resizing each derivative. New split-dir layout via `getPathForCreation()`.
5. **No watermark hook fired** — the importer writes the image programmatically and deliberately does not invoke `actionWatermark`; the asset is already finished.
6. Insert ledger row. On a per-output download/resize failure, record the successful ones, surface a partial-failure diagnostic, do not abort the set.

### Gating (single entry point, Jobs history)
`OutputImporter`/controller evaluates per `qamera_job_id`: row `status='completed'` + has `image/*` output, AND (`job_type='photo_shoot'`) OR (`job_type='packshot'` AND `PackshotReviewRepository::findByJobId($jobId)?->voting === 'accepted'`). Already-imported (every image output has a ledger row) → terminal "imported ✓". The Packshots review view is untouched (it lists only `pending`; accepted rows have left it — which is exactly why the action cannot live there).

### Loop guard (two layers)
- **Layer A (D6):** append/never-cover → an imported scene is not the resolver's primary in the common case.
- **Layer B (ledger exclusion):** `ProductImageSyncService` checks the ledger before upload; if the resolved primary `id_image` is Qamera-origin, it skips upload (treated as null-resolution). Covers the empty-gallery edge (scene becomes the only/first image) and the registered-without-asset recovery path that D2's existing no-op misses.

### Architecture / wiring
New: `src/Packshot/Output/OutputImporter.php` (orchestrates getJob → branch → copyImg → ledger), `src/Packshot/Output/ImportedOutputRepository.php` (+ row VO), `src/Controller/Admin/OutputImportController.php` (AJAX). New route in `config/routes.yml`, services in `config/services.yml`. PS image work isolated behind protected seams (mirroring how `PrimaryImageResolver`/`ProductImageSyncService` wrap PS statics) so units can stub `Image`/`ImageManager` without a live PS.

### Sequencing vs `add-jobs-history-refresh`
No code dependency (`getJob` already shipped). The only coupling is a **merge-conflict surface** on `jobs_history.html.twig` + `jobs_history.js` (both add per-row controls). Whichever merges first, the other rebases. Build independently.

## Risks / Trade-offs

- **Single-output assumption.** Built on the verified current backend (one `output_path`/job). If multi-output ships, switch fetch to `refresh-url` POST (D5 forward-compat note). Risk is contained: the ledger schema already keys on `output_index`.
- **Heavy resize in-request.** Full `ImageType` fan-out can take a few seconds; acceptable in an operator-waiting AJAX call, but the JS must show a spinner and tolerate a slow response (no aggressive client timeout). Mitigation: per-output progress is not streamed in v1; the request returns once the set is done (or partial).
- **Dangling ledger rows.** If the operator deletes an imported `ps_image`, the ledger row persists (no FK) and the job still reports "imported". Acceptable: re-import remains blocked by design (import-once); a future force-replace would clear/replace the row. Documented, not a bug.
- **`isRealImage` / content-type trust.** The signed URL is from our own storage, but the importer still validates the downloaded bytes are a real image before `resize` to avoid writing garbage into the gallery (defense in depth; also guards a mis-typed output).
- **Watermark interaction.** By not firing `actionWatermark` we avoid both double-processing and any chance of re-triggering the sync path during import — but it means imported scenes never get the shop watermark. That is the intended behavior (finished asset); noted so it is not mistaken for a regression.
- **Multistore.** Association is to the single shop in `product_ref`. Multi-shop fan-out of one output is out of scope (consistent with the global-key single-install v1 posture).
