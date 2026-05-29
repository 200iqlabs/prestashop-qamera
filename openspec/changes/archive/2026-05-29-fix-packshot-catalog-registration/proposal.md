## Why

The 2026-05-29 live smoke proved that **no plugin generation job can complete today** — every submission fails:

```
generation_failed / PLUGIN_JOB_MISSING_CATALOG_ENTRY:
asset_id … has no matching product_packshots row … register via POST /plugin/packshots before submitting jobs
```

The upstream adapter (`plugin-job-adapter.ts` `resolveCatalogMetadata`, design §11) resolves `packshot_asset_id` against the **`product_packshots`** table for *both* `packshot` and `photo_shoot` jobs — it is mandatory. The plugin only ever registers **`product_images`** (`POST /images` via `ProductImageSyncService`) and ships that image asset as `packshot_asset_id`. There is no `product_packshots` row, so resolution fails. `auto_register_packshot=true` is **output-side** write-back (it registers the *generated result* as the next stage's packshot) and cannot satisfy the *input* requirement — a chicken-and-egg. `QameraApiClient::registerPackshot()` (`POST /packshots`) exists but is **dead code** — nothing calls it.

The operator confirmed the fix shape end-to-end: registering the synced image's asset as a packshot (`POST /plugin/packshots {external_ref, product_ref, asset_id}` — **no `source_image_ref` needed when `asset_id` is itself a registered, analyzed product-image asset**) made the very next job **complete**.

This is the direct successor to `fix-packshot-asset-id-mismatch` (#21) — same failure class — and a **hard prerequisite** for `add-packshot-acceptance-flow`: stage-1 packshot jobs must complete before any review row can exist.

A second, coupled bug surfaced: the locally stored `qamera_asset_id` **diverges from the catalog asset on re-sync**. Backfilling product 27 with its local `qamera_asset_id` (`c8f9950b…`) still failed `MISSING_CATALOG_ENTRY` because the catalog asset is actually `7458837f…`; the freshly-synced product 28 (no divergence) worked. Cause: each sync mints a *new* presigned upload and overwrites `qamera_asset_id`, but `registerImage` is idempotent on `(installation, external_ref)` so upstream keeps the *original* asset — the new upload is orphaned. Registration is therefore necessary-but-not-sufficient: the asset must also be the catalog's.

## What Changes

- **Register the input packshot before stage-1 submit (wire the dormant client).** `PackshotJobSubmitter` SHALL, for each synced product it submits a `job_type='packshot'` for, first call `QameraApiClient::registerPackshot()` with a **stable** input external_ref (`ps:<shop>:<product>:packshot:src`), `product_ref`, and `asset_id = <catalog qamera_asset_id>`. Idempotent: `created` or `existing` both proceed. No `source_image_ref` (the asset is already a registered, analyzed image). Only then submit the job.
  - This input packshot is distinct from the **output** packshot that `auto_register_packshot=true` + the existing random `packshot_external_ref` register from the job result. Both remain.
- **Keep `qamera_asset_id` catalog-consistent (fix the divergence).**
  - **Prevent**: a re-sync of an already-`registered` product with a non-empty `qamera_asset_id` and an unchanged primary image SHALL NOT mint a new upload nor overwrite `qamera_asset_id`; it reuses the stored asset (re-`registerImage` stays idempotent). New bytes are uploaded only on first registration or when the primary image actually changes (new `id_image`).
  - **Heal**: `AnalysisStatusRefresher` (which already pulls `GET /products/{product_ref}`) SHALL capture `images[0].asset_id` and reconcile `qamera_asset_id` to the authoritative catalog value, repairing any pre-existing divergence on the next poll at no extra round-trip.

## Capabilities

### Modified Capabilities

- `packshot-jobs`: the submitter registers the input packshot (`POST /packshots`) before each stage-1 `job_type='packshot'` submission and sources `packshot_asset_id` from the reconciled catalog `qamera_asset_id`.
- `product-image-sync`: re-sync reuses the stored catalog `qamera_asset_id` instead of orphaning a fresh upload; `AnalysisStatusRefresher` reconciles `qamera_asset_id` from `images[0].asset_id`.

## Impact

- **Code**: `src/Packshot/PackshotJobSubmitter.php` (registerPackshot-before-submit, stable input ref); `src/Sync/ProductImageSyncService.php` (skip re-upload when registered + unchanged); `src/Sync/AnalysisStatusRefresher.php` (capture/reconcile `qamera_asset_id` from product detail). No new table; no new endpoint (client method already exists).
- **DTO**: `RegisterPackshotRequest` already exists and is sufficient (`{external_ref, product_ref, asset_id, source_image_ref?}`).
- **Depends on**: `fix-packshot-asset-id-mismatch` (#21, merged — `qamera_asset_id` column) and `fix-webhook-payload-contract` (#22, merged). Is a **prerequisite of** `add-packshot-acceptance-flow`.
- **Recovery**: no backfill script. Pre-existing divergent rows (smoke products) heal on the next `AnalysisStatusRefresher` poll, or via delete-local-link + re-save. Matches #21's "no backfill, re-save" stance (no installed base to preserve).
- **Out of scope**: content-hash change detection for "image actually changed" (v1 uses `id_image` change; a sha-based refresh is a follow-up); operator-uploaded pre-cleaned packshots (Path B UI); the `job_type='packshot'` semantics of refining the raw image (server-owned).
