## Why

Phase 4.3 ships the BO Products grid and a Generate form that posts to the Qamera AI Plugin API, but it has no signal that the backend's Gemini vision analysis is finished for the product's image. If the operator hits "Generate" before `analysis_status='described'`, the upstream worker stalls with `PREPARE_PHOTOS_TIMEOUT` and the user wastes credits + waits for a webhook that never reports success. The backend now exposes the analysis lifecycle (`saas-platform` PR #204, merged 2026-05-28 — `GET /api/v1/plugin/products/{id_or_ref}` returns `analysis_status` + `analyzed_at` on every `images[]` entry), so the plugin can finally gate the action and tell the operator what the pipeline is doing.

## What Changes

- New per-row visual: the Products grid renders an "Analysis" column with badges `⏳ Pending / 🔄 Processing / ✓ Ready / ⚠ Error`, plus a tooltip carrying `analyzed_at` when known.
- **BREAKING for the canGenerate gate**: the per-row Generate action and the bulk-action filter now additionally require `analysis_status='described'`. Rows with any other status (incl. NULL / legacy / error) render disabled. Bulk-select silently excludes them, mirroring the existing unsynced-skip pattern, with a flash count.
- Local persistence cache: `ps_qamera_product_link` gains four columns (`analysis_status`, `analysis_described_count`, `analysis_total_count`, `analysis_refreshed_at`) so the grid renders from the local DB with zero render-time API calls. NULL = never refreshed, treated as `pending` by the gate.
- Pull-based refresh (backend has no `image.analyzed` webhook):
  - JS poll on the grid page fires every 5s for visible rows whose status is `pending`/`processing`/NULL; rate-limited to max 10 rows per poll cycle (FIFO queue); runs as long as any in-flight row is on screen.
  - Per-row "Refresh" button — synchronous pull, identical pattern to the Jobs history "Refresh" button.
  - Both paths call a new BO endpoint `GET /modules/qameraai/products/{id_link}/status` returning `{analysis_status, generate_enabled, badge_label, analyzed_at}`.
  - TTL on the freshness check: 60s for `{pending, processing, NULL}`, 3600s for `{described, error}`. Decision is per-row, not global.
- New PHP service `AnalysisStatusRefresher` (lives alongside `PrimaryImageResolver` in `src/Sync/`) wraps `QameraApiClient::getProduct($qameraProductRef)`, reduces `images[]` to an aggregate, and writes back to `ps_qamera_product_link`.
- DTO surface: `ProductImageDto` gets two required fields (`analysisStatus`, `analyzedAt`). Backend already returns them, so missing-field decode would surface as `ValidationException` immediately — by spec design.
- Out of scope (deliberate, captured in design.md): per-image drawer / per-image affordance in Generate form (waits for multi-image sync); render-time background batch refresh (premature); "Reanalyze" button (backend has no endpoint); `image.analyzed` webhook handler (backend has no event).

## Capabilities

### New Capabilities

None. All changes fit existing capabilities.

### Modified Capabilities

- `qamera-bo-ui`: Products grid renders an Analysis column + badge; per-row Generate gate adds the `described` requirement; bulk filter silently excludes non-described rows; new per-row Refresh button; new JS poll loop with 10-rows-per-tick rate limit; new BO route `GET /modules/qameraai/products/{id_link}/status` returning the per-row status JSON.
- `qamera-api-client`: `ProductImageDto` declares `analysisStatus: string` (enum `pending|processing|described|error`) and `analyzedAt: ?string` (ISO8601) as required fields, matching the upstream zod schema landed in PR #204. Existing fixture for `products-detail` updated to include both fields.
- `product-image-sync`: schema migration adds `analysis_status`, `analysis_described_count`, `analysis_total_count`, `analysis_refreshed_at` columns to `ps_qamera_product_link`; new `AnalysisStatusRefresher` service pulls `GET /products/{ref}` and writes the aggregate back; `SyncedProductLink::canGenerate()` extends to `qamera_image_id IS NOT NULL AND analysis_status='described'`; `SyncedProductLinkLookup::listForGrid()` SELECT includes the new columns.

## Impact

- **Database (migration)**: `ps_qamera_product_link` gains 4 columns. Migration must be additive — existing rows initialise with `analysis_status=NULL`, `*_count=NULL`, `analysis_refreshed_at=NULL`. Backend backfills the canonical status on its side (per changelog), so the first poll per legacy row pulls the real value with no operator action.
- **Plugin → backend traffic**: each grid render (with in-flight rows) drives a steady ~2 RPS to `GET /products/{ref}` while any row is `pending`/`processing` and the BO tab is open (10 rows × 1 call / 5s = 2 RPS). Within the documented 100 req/min rate limit headroom. No traffic for grids where every row is `described`/`error` (TTL gate stops the poll).
- **Files touched**:
  - `src/Install/Installer.php` — `migrateProductLinkAnalysisColumns()` ALTER path + fresh-install CREATE matching the new shape.
  - `src/Packshot/SyncedProductLink.php` + `SyncedProductLinkLookup.php` — new fields + lookup SELECT.
  - `src/Sync/AnalysisStatusRefresher.php` — new service.
  - `src/Api/Dto/ProductImageDto.php` — two new readonly fields.
  - `src/Controller/Admin/ProductsGridController.php` — wire `analysis_*` into the row dict, render the new column.
  - `src/Controller/Admin/ProductStatusController.php` — new controller for the JSON endpoint.
  - `views/templates/admin/products_grid.html.twig` — new column + badge + Refresh button.
  - `views/js/products_grid.js` — new file (no build step) implementing the 5s poll + FIFO queue.
  - `tests/Contract/Fixtures/products-detail.json` — append the two new fields on `images[]`.
  - `tests/Unit/Sync/AnalysisStatusRefresherTest.php` — new.
  - `tests/Unit/Packshot/SyncedProductLinkTest.php` (or equivalent) — `canGenerate()` matrix update.
- **No dependency changes** — no composer add, no JS framework, no build step (per repo CLAUDE.md constraint).
- **No security impact** — new BO route requires existing PS admin auth; new fields are status enums, not secrets.
- **No backward-compatibility burden upstream** — the Generate gate becomes stricter (operator can no longer click Generate on a `pending` row), but this is the desired behaviour: previous version silently let the operator burn credits on a job that would stall.
