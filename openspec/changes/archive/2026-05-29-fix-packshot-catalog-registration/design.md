# Design — fix-packshot-catalog-registration

## Context

Verified at runtime (2026-05-29, live `qamera.ai`, install `e55c20ec…`):

- `POST /jobs {job_type:'packshot', subjects:[{packshot_asset_id:A, auto_register_packshot:true, …}]}` → **200**, then the job **fails** `generation_failed / PLUGIN_JOB_MISSING_CATALOG_ENTRY` unless `A` is the `asset_id` of an existing `product_packshots` row for the installation.
- `POST /plugin/packshots {external_ref, product_ref, asset_id:A}` with `A` = a registered, **`described`** product-image asset → **200 `{packshot_id, status:'created'}`**, **no `source_image_ref` required** (backend resolves `source_image_id` from the image that owns the asset). The next `job_type='packshot'` with `packshot_asset_id:A` then **completes**, and `outputs[0].url` is the signed preview.
- Registering with an **orphaned** asset (a re-upload not known to the catalog, e.g. product 27's `c8f9950b…` vs catalog `7458837f…`) still fails — `A` must be the catalog asset.

So two independent conditions must both hold for a stage-1 job to complete: (1) a `product_packshots` row exists for `A`; (2) `A` is the catalog's asset for that product's image. This change establishes both.

```
   sync (actionWatermark)                          stage-1 submit (BO "Generate packshot")
   ─────────────────────                           ──────────────────────────────────────
   upload bytes → asset A0   ── first sync ──►  qamera_asset_id = A0  (== catalog asset ✅)
        │
        │  re-sync, image unchanged
        ▼
   D2: SKIP upload, keep A0   (was: mint A1, overwrite → orphan ✗)     POST /packshots {asset_id:A0}  (idempotent)
        │                                                                       │ 200 created/existing
   AnalysisStatusRefresher GET /products/{ref}                                  ▼
   D2b: qamera_asset_id ← images[0].asset_id  (heals any drift)        POST /jobs {packshot_asset_id:A0, auto_register_packshot:true}
                                                                                │ resolveCatalogMetadata(A0) → product_packshots ✅
                                                                                ▼ job completes → outputs[0].url
```

## Decision log

### D1 — Register the INPUT packshot just-in-time in the submitter, idempotent, stable external_ref — RESOLVED

`PackshotJobSubmitter::submitChunk` registers the input packshot via `QameraApiClient::registerPackshot()` immediately before `submitJob()`, for each link in the chunk:

```
RegisterPackshotRequest(
    externalRef: sprintf('ps:%d:%d:packshot:src', $link->idShop, $link->idProduct),  // STABLE
    productRef:  $link->qameraProductRef,            // ps:<shop>:<product>
    assetId:     (string) $link->qameraAssetId,      // the catalog asset (see D2/D2b)
    // sourceImageRef: omitted — asset is already a registered, analyzed image
)
```

- **Stable** input external_ref (`…:packshot:src`) so re-submits are idempotent (`created` first time, `existing` after) — no row churn, safe to call on every Generate.
- This is the **input** packshot (the raw image as the cleanup source). It is distinct from the **output** packshot that `auto_register_packshot=true` writes back from the job result under the existing *random-uuid* `packshot_external_ref` (kept as-is). Two different external_refs, two different rows, two different roles.
- `auto_register_packshot=true` stays: the generated output must land in `product_packshots` so the acceptance gate / `photo_shoot` resolution can find the accepted packshot per `product_ref`.
- A `registerPackshot` failure (`ApiException`) aborts that subject's submission and records the chunk failure — no job is submitted that would fail `MISSING_CATALOG_ENTRY`.

Rejected: registering the input packshot at **sync time**. Sync is triggered by `actionWatermark` on every product save and would register packshots for products the operator never generates; just-in-time keeps registration coupled to generation intent and self-heals (idempotent each submit). (Both are functionally valid since no `source_image_ref` is needed; submit-time is the lower-waste choice.)

### D2 — Re-sync reuses the catalog asset instead of orphaning a fresh upload — RESOLVED

Root cause of the divergence: `ProductImageSyncService` mints a new presigned asset and PUTs bytes on **every** sync, then overwrites `qamera_asset_id`; but `registerImage` is idempotent on `(installation, external_ref)`, so upstream keeps the **first** asset. Result: local drifts from catalog on each re-save.

Fix: when the bookkeeping row is already `status='registered'` with a non-empty `qamera_asset_id`, the service SHALL skip the upload+register entirely and leave `qamera_asset_id` intact. A registered row **without** an asset still re-registers (recovery, e.g. after the #21 migration nulled the column). New bytes are uploaded only on first registration (`status` in `pending`/`error`).

**v1 limitation (deliberate, no `id_image` tracking):** the guard does NOT compare the resolved primary `id_image` against a prior value — the plugin persists no prior `id_image`, so a genuinely changed primary image is NOT auto-re-synced. This is an accepted cost of the flattened single-image v1 model and is preferable to the old behavior (re-upload on every re-sync), which was the divergence bug itself. It does **not** break packshot submits: the retained `qamera_asset_id` still resolves to a valid catalog `product_packshots`/image asset; only the *new* image is not reflected. Recovery: delete the local link + re-save (fresh first-sync), or rely on the D2b refresher, which keeps `qamera_asset_id` reconciled to whatever the catalog holds. Rejected as follow-ups (would need a persisted signal → schema change, out of scope here): storing the prior `id_image`, or hashing the bytes to detect in-place replacement.

### D2b — AnalysisStatusRefresher reconciles `qamera_asset_id` to the catalog (heal) — RESOLVED

`AnalysisStatusRefresher` already calls `GET /products/{product_ref}` and reduces `images[]` to the analysis-status cache. It SHALL additionally read `images[0].asset_id` (the flattened single-image model's authoritative catalog asset) and, when it differs from the stored `qamera_asset_id`, update the column to the catalog value. This:

- **heals** rows that diverged before this change (e.g. the smoke products) on the next poll, at zero extra round-trip;
- is a backstop to D2 (defense-in-depth), not the primary mechanism.

`images[0]` is well-defined under the plugin's flattened one-image-per-product model. If `images` is empty, leave `qamera_asset_id` unchanged (don't null a working value on a transient read).

### D3 — No backfill for already-diverged rows — RESOLVED

There is no installed base (per upstream `add-plugin-packshot-acceptance-gate` notes); the only divergent rows are the operator's smoke products. They self-heal via D2b on the next BO grid view / refresh, or by deleting the local link and re-saving (fresh first-sync is correct). A dedicated reconcile script is over-engineering — matches `fix-packshot-asset-id-mismatch` D-decisions ("no backfill, re-save").

## Out of scope

Operator-uploaded pre-cleaned packshots (Path B BO UI), content-hash image-change detection, and any change to the `job_type='packshot'` generation semantics (the server refines the registered input packshot — plugin-opaque).
