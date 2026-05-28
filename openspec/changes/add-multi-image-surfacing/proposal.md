## Why

Today `PrimaryImageResolver` flattens each PrestaShop product to a single cover image at sync time, so `ps_qamera_product_link` carries one `qamera_image_id` per product and the BO grid shows one analysis status per row. The canonical Qamera data model is per-image: a product has N images, each image has its own `analysis_status` and its own set of packshots, and a `photo_shoot` session is generated **per image** against an accepted packshot on that image. Without surfacing the full per-image model the operator cannot:

- pick which image drives a packshot/photo_shoot session (single-cover assumption is silently lossy when products carry multiple meaningful shots);
- see per-image analysis progress, only the aggregate `partial` state introduced by `add-analysis-status-surfacing`;
- act on the `add-packshot-acceptance-flow` voting gate, which is itself per-packshot and therefore per-image upstream.

Forward-compat code already in place (`analysis_total_count`, `analysis_described_count`, `partial` enum, "(k of n)" badge suffix) becomes meaningful only once this change lands.

## What Changes

- New table `ps_qamera_product_image` mirroring upstream `ProductImageDtoSchema`: `(id_product_image PK, id_link FK, qamera_image_id, qamera_asset_id, ps_image_id, position, analysis_status, analysis_described_at, last_synced_at, deleted_at NULL)`. One row per upstream image; soft-delete on upstream image removal.
- `ProductImageSyncService` no longer flattens via `PrimaryImageResolver` by default — registers every PS image not yet known upstream and tracks them individually. `PrimaryImageResolver` retained as fallback for operator-driven cover-only flow and for legacy rows that pre-date this change.
- BO grid row gains expand affordance (drawer or expandable row, decision in design.md) listing each image with its own analysis badge, per-image last-synced timestamp, and per-image "Generate" entry point. Top-level row keeps the aggregate badge from `add-analysis-status-surfacing` for compact view.
- `SyncedProductLink::canGenerate()` semantics shift: aggregate-level `canGenerate` keeps current contract (any image generatable) but a new `SyncedProductImage::canGenerate()` is the per-image gate the new Generate flow actually consumes.
- `AnalysisStatusRefresher` writes per-image rows into the new table in addition to (or instead of — TBD in design.md) the aggregate columns on `ps_qamera_product_link`.
- **BREAKING** for any downstream caller relying on "one link = one image" assumption; in-tree only — no public API surface exposes this.

## Capabilities

### New Capabilities

- `product-image-catalog`: per-image persistence, lifecycle (registered → analyzed → packshots-pending → described → deleted), and lookup APIs the BO grid and packshot submitter consume.

### Modified Capabilities

- `product-image-sync`: registration loop iterates all PS images, not just primary; persistence target moves from `ps_qamera_product_link.qamera_image_id` to `ps_qamera_product_image` rows; `PrimaryImageResolver` demoted to fallback.
- `qamera-bo-ui`: grid row gains expand affordance + per-image badge column; bulk-select semantics extend to "at least one described image" instead of "the cover image described".
- `packshot-jobs`: submitter input contract switches from per-link to per-image; one job request per selected image instead of per selected product.

## Impact

- **Code**: new `src/Sync/ProductImageCatalog.php` + DTO `SyncedProductImage`, refactor of `ProductImageSyncService`, new lookup/repository, BO Twig template additions (expandable row), JS expand handler.
- **Schema**: new table + foreign key; backfill from existing `ps_qamera_product_link` (one row → one `ps_qamera_product_image` carrying current `qamera_image_id` / `qamera_asset_id`) so post-upgrade state preserves what the operator already sees.
- **Depends on**: `fix-packshot-asset-id-mismatch` landed first (this change inherits the corrected asset_id discipline; doing it backwards forces a second backfill).
- **Unlocks**: `add-packshot-acceptance-flow` (per-image voting UI only makes sense once images are individually addressable in the plugin).
- **Out of scope**: dragging/reordering images, image upload from PS BO into Qamera (PS remains the source of truth for which images exist).
