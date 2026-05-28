# Design — fix-packshot-asset-id-mismatch

## Context

The Phase 4.3 upload flow conflates two distinct upstream identifiers:

- `ImageResponse.imageId` — logical PK of the image record in the upstream catalog (returned by `POST /images`).
- `PresignedUploadResponse.assetId` — opaque identifier of the actual bytes in storage (minted by `POST /assets/upload`, used as the PUT target and passed to `registerImage`).

`ProductImageSyncService::syncOnImageAdded()` (`src/Sync/ProductImageSyncService.php`) has BOTH in scope:

```
$assetId = $this->uploadStrategy->uploadImage(...);          // line ~114  → PresignedUploadResponse.assetId
$request = new RegisterImageRequest($externalRef, $productRef, $assetId, $metadata);  // line ~125 — sends the correct asset_id
$response = $this->apiClient->registerImage($request);       // returns ImageResponse (imageId, productId, status)
$this->persistSuccess($idProduct, $idShop, $isRegistered, $response);  // line ~128 — persists imageId, DROPS $assetId
```

`persistSuccess()` then writes `$response->imageId` into `qamera_image_id` (lines ~204, ~216, ~243). `PackshotJobSubmitter::submitChunk()` (`src/Packshot/PackshotJobSubmitter.php:188`) reads it back as `(string) $link->qameraImageId` into `Subject.packshotAssetId`. The upstream storage resolver looks up `packshot_asset_id` in object storage, finds nothing under the logical imageId, and returns `generation_failed / "No source upload found"`. Confirmed against the live backend during smoke; CI stayed green because `FakeSyncedProductLinkLookup` stubs never round-trip a real backend.

## Constraint that shaped every decision: no clients, no compat

The plugin has not shipped commercially. There is no integration to keep compatible and no client data to preserve. The only persisted rows live on the operator's own `pracownia-qamery-ai` smoke install. This removes the entire class of "additive, reversible, backfill-the-old-data" tradeoffs that would otherwise dominate a schema fix, and lets us optimize purely for a clean end state ("no garbage", per operator instruction 2026-05-28).

## Decision log

### D1: New parallel column vs. rename — RESOLVED → rename

**Decision**: Rename `qamera_image_id` → `qamera_asset_id`. Do not add a parallel column; do not keep the logical `imageId` anywhere.

**Rationale**:
- The column name `qamera_image_id` is itself a source of the bug — it advertises "image id" while every consumer treats it as the packshot source asset. Renaming makes the schema honest.
- A grep of `qamera_image_id` / `qameraImageId` across `src/` shows every reader feeds either `Subject.packshot_asset_id` (`PackshotJobSubmitter`) or the Generate-readiness gate / grid display (`SyncedProductLink`, `SyncedProductLinkLookup`, `ProductsGridController`, `ProductStatusController`, `GenerateFormController`). None consumes the logical image id *as* an image id. So nothing of value is lost by dropping it.
- The original proposal's parallel-column approach existed only to avoid touching existing client data — a constraint that does not apply (see above).

**Rejected — parallel `qamera_asset_id` alongside `qamera_image_id`**: leaves a permanently-unused, misleading column. That is exactly the "garbage" the operator asked to avoid.

### D2: How to repair existing rows — RESOLVED → null + re-save, no backfill script

**Decision**: The migration nulls the carried-over values; the operator re-saves the affected products so the `actionWatermark` hook repopulates `qamera_asset_id` with the correct storage id. No `GET /products/{ref}` backfill machinery.

**Rationale**:
- A resumable, rate-limit-aware backfill loop is the correct tool when you must repair *client* data you cannot ask anyone to re-enter. With no clients, the affected set is a handful of the operator's own smoke products.
- The renamed column would otherwise carry the *wrong* (logical imageId) values forward. Those must be nulled regardless — a non-null wrong value would pass the Generate gate and reproduce the silent failure. Once nulled, the cheapest correct repopulation is the existing, already-tested hook path (re-save → `actionWatermark` → sync), not a new code path.
- Keeps the change small and free of throwaway scripts.

**Rejected — keep the one-shot backfill script**: more code, a second network path to test and maintain, to save the operator from re-saving ~a dozen products once. Net negative here. (A *general* bulk re-sync for real catalogues is a separate, independently-justified change: `add-bulk-sync-action`.)

### D3: Generate-readiness gate — RESOLVED → gate on `qamera_asset_id`

**Decision**: `SyncedProductLink::canGenerate()` becomes `qameraAssetId !== null/'' AND analysisStatus === 'described'` (was keyed on `qameraImageId`).

**Rationale**:
- The gate must reflect the value that actually makes a job succeed. After the fix that value is the storage `asset_id`. Gating on anything else re-opens the silent-failure door: a row with a stale/NULL `qamera_asset_id` but a set image marker would show "Generate" enabled and then fail at generation.
- New syncs write `qamera_asset_id` and the analysis columns from the same successful `registerImage`/refresh paths, so for any freshly-synced row the gate behaves exactly as before — only legacy (pre-migration, now-nulled) rows diverge, and they *should* be blocked until re-synced.

**Hint behaviour**: a NULL `qamera_asset_id` reuses the existing "Sync this product first" disabled hint (the same precedence rule the analysis-status change already defined for a NULL image marker). No new hint string, no new UI state — the operator re-saves and the row lights up. (Considered and rejected a distinct "missing storage id — re-sync" hint: extra translations and UI surface for a transient migration-only state on a no-client install.)

### D4: ImageResponse DTO — RESOLVED → keep the field, stop persisting it

**Decision**: `ImageResponse` keeps its `imageId` property (it honestly mirrors the `POST /images` response, which does return `image_id`). `persistSuccess()` simply stops reading it. `persistSuccess()` gains the storage `asset_id` as an explicit parameter (the caller already holds `$assetId`).

**Rationale**: trimming a DTO field that mirrors a live upstream field buys nothing and erodes contract parity. The fix is about what we *persist*, not about the wire DTO. `persistSuccess($idProduct, $idShop, $isRegistered, $assetId)` — `$response->productId` is still needed for the `registered` transition, so the signature carries both the response (for productId) and the asset id. Final shape decided during implementation; the spec only mandates that the stored value is the storage `asset_id`.

## Migration shape

`upgrade-1.5.0.php` (mirrors the `upgrade-1.4.0.php` INFORMATION_SCHEMA-guarded pattern):

1. Probe `INFORMATION_SCHEMA.COLUMNS` for `ps_qamera_product_link`.
2. If `qamera_image_id` is present AND `qamera_asset_id` is absent → `ALTER TABLE ... CHANGE COLUMN qamera_image_id qamera_asset_id CHAR(36) NULL;` (idempotent: re-running after the rename is a no-op because the guard fails).
3. `UPDATE ... SET qamera_asset_id = NULL;` — drop the carried-over (wrong) logical-id values so the gate cannot pass on stale data.
4. On any failed statement: log at severity 3 via `PrestaShopLogger::addLog` and return false (same convention as 1.4.0).

`Installer::createTables()` fresh-install DDL and the `migrateProductLinkSchema()` `$additions` array both rename their `qamera_image_id` entry to `qamera_asset_id`, so a fresh install never creates the old name and the idempotent ADD path never resurrects it after the rename.

**Ordering note**: because the `$additions` array (used by `migrateProductLinkSchema()`) keys on the *new* name, an upgrade that runs the rename in step 2 leaves the column present as `qamera_asset_id`, so the additions probe skips it — no duplicate column. A truly fresh install creates `qamera_asset_id` in `createTables()` and the migration is a no-op.

## Out of scope

- Bulk re-sync of a pre-existing catalogue (general operator pain) → `add-bulk-sync-action`.
- Multi-image per product (`ps_qamera_product_image` carrying both `qamera_image_id` and `qamera_asset_id`) → `add-multi-image-surfacing`. That change reintroduces a logical image id *in its own table* where it is genuinely needed for per-image matching; it does not depend on `ps_qamera_product_link` keeping one.
- Any `job_type` / acceptance-gate behaviour → `add-packshot-acceptance-flow` (blocked on this fix).
