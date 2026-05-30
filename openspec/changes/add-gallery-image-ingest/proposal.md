## Why

The module can only get images into Qamera through PrestaShop hooks (`actionProductSave` / `actionWatermark`), and it can only *show* the operator a single flattened cover image per product. Two operator needs go unmet:

1. **Ingest existing store gallery images on demand** — a merchant cannot pick an arbitrary product-gallery image and push it into Qamera as a product image and/or a generation-ready packshot. Pre-existing inventory and non-cover shots never reach the platform (relates to the backfill gap, but this is the per-product, hand-picked surface, not the catalog-scale bulk runner of `add-bulk-sync-action`).
2. **See what actually lives in Qamera for this product** — the operator has no view of the per-image model: each product image, the packshots hanging off it, and the photo-shoot session outputs generated from those packshots. Today they see one cover badge and nothing about packshots or session results.

The backend already ships every endpoint required for both halves (per the `plugin-gallery-ingest-contract` integration doc); this change is plugin-side only.

## What Changes

A new **"Qamera" tab on the PrestaShop product-detail page** with two halves:

**Ingest (push) — gallery picker**
- Source toggle "Select from store gallery" / "Upload new"; gallery grid of this product's PS images, multi-select.
- Per selection: **"Add as product"** (Flow A — register image) and **"Add as packshot"** (collapse Flow B→C into one deterministic path: register the image first via the idempotent `/images`, then register the packshot with `source_image_ref` so `source_image_id` is always non-null — sidesteps the null-source photo_shoot trap entirely).
- Bytes uploaded **server-side** via the existing `PresignedImageUploadStrategy` (presigned PUT, no browser CORS), then referenced by `asset_id`.
- `external_ref` namespace **reuses the existing hook-sync scheme** `ps:<shop>:<prod>:image:<imageId>` for images and adds a parallel `ps:<shop>:<prod>:pack:<imageId>` for packshots — NOT the contract doc's suggested `ps:<shop>:img:<id>`, because diverging would break Flow B linking against already-hook-synced images.
- Synchronous chunked AJAX, one item at a time, live per-item status (uploading → registering → analyzing → ready) driven by polling `GET /products/{ref}` embedded `analysis_status`. No background job/queue — that is `add-bulk-sync-action`'s concern.
- Scope precheck: block the feature with a clear message if the installation lacks `plugin.catalog:write` (HTTP 403 `forbidden`).
- Full error taxonomy → UI mapping (`invalid_input`, `unauthorized`, `forbidden`, `not_found`, `source_asset_unavailable`, `rate_limit_exceeded`, `internal_error`).

**Browse (pull) — per-image accordion**
- Accordion rows, one per Qamera product image. Collapsed: thumbnail + analysis badge + counts (📦 packshots, 🎬 session images). Expanded: two thumbnail strips — packshots and photo-shoot session images — with lightbox.
- **Add generated assets back to the PrestaShop gallery, origin-guarded.** Per displayed photo-shoot session image and generated packshot, an "Add to product gallery" action delegates to the existing `qamera-output-import` per-output machinery (download → resize → append, no cover steal, no watermark, ledger-idempotent). The action is NOT offered for gallery-origin assets — the product/main image and packshots ingested from gallery images — so an asset is never re-imported into the gallery it came from. The origin guard is largely structural: only job-output-backed assets are importable, and ingested packshots/product images have no backing job output.
- Hierarchy assembled live: `GET /products/{ref}` gives `images[]` + `packshots[]` (grouped under each image by `packshot.sourceImageId`); session images come from walking `GET /jobs` (filter `jobType==photo_shoot` + `productRef`, client-side — the jobs filter has no `productRef` param), mapping `job.outputs[]` back to an image via `job.packshotAssetId → packshot.assetId → packshot.sourceImageId`. Session jobs fetched **lazily on row expand** to bound the jobs walk.
- **Every object renders a thumbnail.** Sourcing: session image → `JobOutput.url` (signed, direct); product image → local PrestaShop file resolved from the `ps:<shop>:<prod>:image:<id>` external_ref; packshot → if `generatedByJobId == null` (ingested, asset == source image) the source image's PS-local thumb, else `getJob(generatedByJobId).outputs[].url`.
- **Cover-only persistence retained** — the plugin's `ps_qamera_product_link` table is NOT extended to per-image rows (does not block on `add-multi-image-surfacing`); the browse view reads the per-image model live from the backend and persists nothing extra.

## Capabilities

### New Capabilities

- `gallery-image-ingest`: operator-driven, per-image push of PrestaShop gallery images into Qamera as product images and/or auto-accepted packshots — selection, server-side byte upload, idempotent register (image-then-packshot), external_ref namespacing, per-item progress, scope precheck, and error-taxonomy mapping.
- `product-image-browse`: per-image read view assembling image → packshots → photo-shoot session outputs from `/products` + `/jobs`, with a thumbnail-sourcing strategy for every object kind and lazy session-image loading.

### Modified Capabilities

- `qamera-bo-ui`: a new "Qamera" tab is mounted on the product-detail page hosting both the ingest picker and the browse accordion (existing Qamera Products grid is unchanged); browse rows expose an origin-guarded "Add to product gallery" action.
- `qamera-output-import`: a per-output (single `output_index`) import trigger is added, callable from the browse view, reusing the existing ledger / fresh-fetch / placement / idempotency and the same type+acceptance eligibility gate.

## Impact

- **Code**: new `src/Gallery/GalleryIngestOrchestrator.php` (per-image upload+register, Flow A→B collapse, ordering guard), new external_ref builders for `image:`/`pack:` namespaces (extending `ProductRefBuilder` usage), new `src/Gallery/ProductImageBrowseAssembler.php` (hierarchy + thumbnail sourcing + lazy jobs walk), a product-detail BO controller hook (`displayAdminProductsExtra` or equivalent) + Twig template + JS (accordion, gallery picker, lightbox, AJAX driver).
- **API client**: reuses existing `requestUpload`, `registerImage`, `registerPackshot`, `getProduct`, `listJobs`, `getJob` — no new endpoint. May add a thin client-side helper to filter jobs by `productRef`.
- **Schema**: none (no new tables; cover-only persistence unchanged).
- **Reuses**: `PresignedImageUploadStrategy`, `RegisterImageRequest`/`RegisterPackshotRequest` DTOs (already carry every contract field including `source_image_ref` and `product_metadata`), `ProductDetailResponse`/`ProductImageDto`/`ProductPackshotDto`/`JobDto`/`JobOutput`.
- **Out of scope**: per-image persistence in `ps_qamera_product_link` (deferred to `add-multi-image-surfacing`); catalog-scale bulk ingest + background queue (`add-bulk-sync-action`); a catalog-level (cross-product) gallery picker — this surface is per-product only. Importing session outputs back into the PS gallery is IN scope here as a per-output, origin-guarded browse action reusing `qamera-output-import`; the existing per-job "Download to shop" action in the jobs-history grid is unchanged.

## Open Questions (for design.md)

- **Synthesized-image thumbnails**: a Flow-C fallback image (no PS origin) has no local file and no signed URL — render a placeholder, derive from a related packshot, or request an upstream signed-thumbnail field? (Edge case; this surface registers from PS so images normally have a PS file.)
- **Jobs walk cost**: `GET /jobs` has no `productRef` filter; client-side filtering pages all jobs. Lazy-on-expand bounds it, but a busy install still pages broadly — accept, cap, or ask upstream for a filter param?
- **`imagesTruncated` / `packshotsTruncated`**: `GET /products/{ref}` caps embedded arrays; large products silently lose rows — surface a "truncated" notice, or paginate?
- **Long-term thumbnail clean-up**: ask upstream to add a signed thumbnail URL to `ProductImageDto`/`ProductPackshotDto` (the contract doc claims "no backend change required" — this would be a deliberate amendment that removes the `getJob`-per-packshot derivation).
