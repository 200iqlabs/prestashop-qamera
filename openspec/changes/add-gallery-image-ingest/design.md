## Context

The module already mounts a product-detail extension point — `displayAdminProductsExtra` is registered by the installer and `hookDisplayAdminProductsExtra()` exists as a returning-`''` stub (qameraai.php:213), explicitly reserved for the "Qamera AI" product-page tab. Back-office AJAX surfaces follow an established pattern: Symfony admin controllers under `src/Controller/Admin/` (e.g. `ProductStatusController`, `OutputImportController`, `JobStatusController`, `PackshotReviewController`).

Every wire primitive this change needs already exists in `QameraApiClient`: `requestUpload`, `registerImage`, `registerPackshot`, `getProduct`, `listJobs`, `getJob`; the request DTOs (`RegisterImageRequest`, `RegisterPackshotRequest`) already carry `external_ref`, `product_ref`, `product_metadata`, `source_image_ref`; byte delivery is done by `PresignedImageUploadStrategy` (server-side presigned PUT). The backend ships the full ingest + read contract (`plugin-gallery-ingest-contract`), so this change is plugin-side only.

This change fills the stub with a tab that does two things on one product: **push** selected gallery images into Qamera, and **browse** the per-image model (image → packshots → photo-shoot session outputs) already living upstream.

## Goals / Non-Goals

**Goals:**
- Operator picks arbitrary PS product-gallery images and pushes each as a product image and/or an auto-accepted, generation-ready packshot.
- Operator sees, per product, every Qamera image with its packshots and photo-shoot session outputs, each rendered with a thumbnail.
- Plugin-only: no backend change, no new schema, no extension of `ps_qamera_product_link`.
- Guarantee non-null `source_image_id` on every ingested packshot (avoid the null-source photo_shoot trap).

**Non-Goals:**
- Per-image persistence in the plugin DB (deferred to `add-multi-image-surfacing`; browse reads upstream live).
- Catalog-scale bulk ingest, background queue/worker (`add-bulk-sync-action`).
- Cross-product / catalog-level gallery picker (this surface is per-product only).
- Importing session outputs into the PS gallery (`qamera-output-import` already covers it).
- Multistore per-shop keys (OQ-PS marker still in effect).

## Decisions

### D1 — Mount on the existing `displayAdminProductsExtra` stub
Fill `hookDisplayAdminProductsExtra()` to render the "Qamera" tab (Twig + JS bundle injected via `displayBackOfficeHeader`, also already a stub). *Alternative considered:* PS 8/9 Symfony product-page tab API (`hookActionGetAdminProductsTabs` + a controller-rendered pane). Rejected for v1 — the legacy `displayAdminProductsExtra` hook is already registered, already PS-8/9 compatible, and matches the module's existing surfaces; adopting the Symfony tab system is a larger lift with no functional gain here.

### D2 — Ingest path: collapse Flow B/C into a deterministic image-then-packshot sequence
"Add as product" → `registerImage` only. "Add as packshot" → always `registerImage` first (idempotent on `external_ref`), then `registerPackshot` with `source_image_ref` set to that image's ref. This makes `source_image_id` non-null in every case and removes any need to detect "does the image already exist upstream" (Flow B vs C). *Alternative:* literal Flow C (omit `source_image_ref`, let backend synthesize image+product). Rejected — only pays off in a catalog-level picker with no PS product context; here the PS product always exists, and the collapse is simpler and trap-proof.

### D3 — external_ref namespace: keep existing hook-sync scheme (chosen on merit, not compat)
Image ref = `ps:<shop>:<prod>:image:<psImageId>` (the exact format `ProductImageSyncService` already mints at line 170), packshot ref = `ps:<shop>:<prod>:pack:<psImageId>`.

The plugin is pre-production with no installs, so there is **no backward-compat obligation** — we are free to change the hook-sync scheme too. The only hard constraint is that the picker and `ProductImageSyncService` agree with each other: a packshot's `source_image_ref` must equal the stored image `external_ref`, or Flow B linking fails `invalid_input`. A single shared ref-builder enforces that for both call sites.

Given that freedom, keeping the current `ps:<shop>:<prod>:image:<id>` is chosen on merit: PS `id_image` is globally unique so the ref is unambiguous, the product segment is self-describing and harmless, and it means **zero churn** to existing hook-sync code. *Alternative:* the contract doc's `ps:<shop>:img:<id>` (drops the product segment). Marginally shorter, no functional gain, and would force editing `ProductImageSyncService` + re-syncing the dev store — rejected as churn for churn's sake. Decision is reversible if a reason emerges.

### D4 — Synchronous chunked AJAX, no queue
Per-product, interactive, few images → one controller call per item, live per-item status (uploading → registering → analyzing → ready), `analysis_status` polled from `GET /products/{ref}`. *Alternative:* background job/queue. Rejected — that's catalog-scale (`add-bulk-sync-action`) territory; overkill for a handful of images.

### D5 — Browse hierarchy assembled live (no persistence)
`ProductImageBrowseAssembler` builds the tree from `GET /products/{ref}` (`images[]` + `packshots[]`, grouped under each image by `packshot.sourceImageId`). Session images come from walking `GET /jobs`, filtering `jobType==photo_shoot` + `productRef` client-side, mapping `job.outputs[]` back to an image via `job.packshotAssetId → packshot.assetId → packshot.sourceImageId`. *Alternative:* persist per-image rows. Rejected — couples to `add-multi-image-surfacing`; live read keeps this change independent and always-fresh.

### D6 — Jobs walk: lazy-on-expand + capped paging (resolves open Q2)
`GET /jobs` has no `productRef` filter. Walk jobs ONLY when an accordion row is expanded (not on tab load), page via the existing cursor up to a cap (~200 jobs / a few pages), filter client-side. If the cap is hit before exhaustion, show a "showing recent sessions" notice. Bounds cost with no backend change. *Alternative:* full walk (slow on busy installs) or block on an upstream `product_ref` filter (couples to saas-platform cadence). Both rejected.

### D7 — Thumbnail sourcing per object kind (resolves open Q1 + Q4)
- **Session image** → `JobOutput.url` (signed, direct; same source the 4.5 downloader uses).
- **Product image** → local PrestaShop file resolved by parsing `psImageId` out of the `ps:<shop>:<prod>:image:<id>` external_ref → PS image thumbnail.
- **Packshot, ingested** (`generatedByJobId == null`, asset == source image) → the source image's PS-local thumb via `sourceImageId`.
- **Packshot, generated** (`generatedByJobId` set) → `getJob(generatedByJobId).outputs[].url`.
- **Synthesized image** (no PS origin) → derive from a related packshot's thumbnail (per Q1); placeholder + label if it has no packshot.
- **Long-term (Q4):** request an upstream signed-thumbnail URL on `ProductImageDto`/`ProductPackshotDto` to delete the `getJob`-per-packshot derivation. Documented as a future simplification only — NOT a task in this change (keeps it plugin-only).

### D8 — Accordion UX, every object thumbnailed
One collapsible row per Qamera product image. Collapsed: thumbnail + analysis badge + counts (📦 packshots, 🎬 session). Expanded: two thumbnail strips (packshots / session images) with a lightbox. Session jobs fetched lazily on expand (ties to D6). Reuse PrestaShop's bundled fancybox for the lightbox rather than adding a JS dep.

### D9 — Truncation: notice, no pagination (resolves open Q3)
When `imagesTruncated` / `packshotsTruncated` is set, render returned rows + an inline "truncated, some not shown" warning. Per-product counts are normally small; pagination of embedded arrays is over-engineering.

### D11 — Add-to-gallery from browse: reuse output-import per-output, origin guard is structural
Browse exposes "Add to product gallery" on session images and generated packshots, delegating to a new per-output (`output_index`) trigger on the existing `qamera-output-import` machinery (ledger keyed `(qamera_job_id, output_index)` already supports per-output; placement/freshness/idempotency unchanged; same type + packshot-acceptance gate). The origin guard ("don't re-import gallery-origin assets") is mostly structural rather than a new check: only job-output-backed assets are importable, and the gallery-origin assets — the product/main image and ingested packshots (`generatedByJobId == null`, asset == a PS image) — have no backing job output, so the UI simply offers no action for them. *Alternative:* an explicit origin-marker comparison per asset. Unnecessary — the job-output backing already encodes origin. The existing 4.5 loop-guard import marker still prevents a placed output from later re-syncing back upstream.

### D10 — Scope precheck
Before rendering ingest actions, verify the installation has `plugin.catalog:write` (from `/me` scopes, already fetched/cached). Missing → block the picker with a clear message; a live `403 forbidden` maps to the same. Browse (read) requires only `plugin.catalog:read`.

## Risks / Trade-offs

- **Jobs walk incompleteness** → cap may hide old sessions. Mitigation: lazy-on-expand + explicit "recent sessions" notice (D6); revisit if upstream adds a `product_ref` filter.
- **Generated-packshot thumbnail cost** → one `getJob` per generated packshot. Mitigation: only on row expand, batch/parallelize within a row, cache per tab session; the Q4 upstream field removes it entirely later.
- **Synthesized-image thumb is approximate** → shows a packshot, not the image itself. Mitigation: acceptable (rare on a from-PS surface), labelled; placeholder fallback.
- **external_ref drift** → if any other code path mints a different image ref, Flow B breaks. Mitigation: D3 reuses the single existing format; a shared builder keeps both call sites aligned.
- **Truncated arrays** → big products lose rows in browse. Mitigation: D9 notice (honest, not silent).
- **Re-ingest of an already-synced cover** → idempotent on `external_ref` (returns `existing`), safe; no duplicate, asset immutable.

## Migration Plan

- Pure additive: fills two existing hook stubs, adds controllers + Twig + JS + two services. No schema change, no data migration.
- Deploy in the main checkout (bind-mount is the only path PS resolves `QameraAi\Module\…` from); `cache:clear` as `www-data` after deploy.
- Rollback: revert the hooks to stubs (return `''` / no-op) — no persisted state to unwind.

## Open Questions

All four proposal open-questions resolved above (Q1→D7, Q2→D6, Q3→D9, Q4→D7). Remaining minor:
- Exact jobs-walk cap value (200?) — tune during smoke against the dev store.
- Whether to memo-cache the per-tab browse assembly across row expands within one page load (perf nicety, not correctness).
