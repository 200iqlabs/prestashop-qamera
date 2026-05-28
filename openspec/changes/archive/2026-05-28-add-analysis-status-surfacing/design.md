## Context

Phase 4.3 (`add-packshots-ui`, archived 2026-05-28) shipped the BO Products grid, Generate form, and Jobs history. Today the grid renders solely from `ps_qamera_product_link` and the Generate button enables iff `qamera_image_id IS NOT NULL`. The backend's `PREPARE_PHOTOS_TIMEOUT` failure mode — a job submitted before Gemini finished analysing the source image — was previously invisible to the operator: they clicked Generate, the upstream worker stalled, and a webhook reported failure minutes later with no actionable hint.

`saas-platform` PR #204 (merged 2026-05-28) added `analysis_status` (`pending|processing|described|error`) and `analyzed_at` to every `images[]` entry in `GET /api/v1/plugin/products/{id_or_ref}`. The plugin team's job is to surface that signal so the operator knows when a product is *truly* ready to generate.

Three hard constraints from the backend side, established during `/opsx:explore`:

1. **No `image.analyzed` webhook.** The plugin webhook registry is `job.{completed,failed,cancelled,retried}` only (verified at `packages/features/content-generation/src/server/services/webhook-delivery-enqueue.service.ts:23-26`). There is no push channel for image lifecycle.
2. **No `/reanalyze` endpoint.** Grep on `/api/v1/plugin/**` returns zero matches. An `error` state is recovered only by re-syncing the image (delete + re-register on the plugin side).
3. **`/products` list omits `images[]`.** `ProductListItemSchema` (`schemas.ts:774`) exposes `image_count` but not the per-image lifecycle. The new fields live only on `GET /products/{id_or_ref}` detail. Reading status for N grid rows therefore costs N detail calls absent a local cache.

Two soft constraints from this repo's CLAUDE.md:

- No NPM, no build step, no JS framework. Plain ES5/ES6 only, jQuery + Bootstrap 4 available globally.
- PSR-12 + PHPStan level 5 + PHP 8.1+ baseline.

## Goals / Non-Goals

**Goals:**

- The operator can tell at a glance whether each product on the grid is ready to generate, with a status badge and a tooltip carrying `analyzed_at`.
- Generate (per-row + bulk) gates on `analysis_status='described'`. Clicking is blocked for any other state, with a clear reason in the disabled button's `title`.
- Status appears fresh enough to be useful without burning the rate limit: rows freshly seen as `pending`/`processing` poll every 5s (rate-limited); rows that have settled into `described`/`error` do not poll at all until TTL expiry on the next page render.
- Legacy rows (synced before PR #204) "heal" themselves on first poll with zero operator intervention — backend changelog confirms it backfills `analysis_status` from the worker's own column.
- The whole change is additive at the DB level (one `ALTER TABLE` with 4 nullable columns) and reversible.

**Non-Goals:**

- Per-image affordance in the Generate form (multi-checkbox or per-image disable). The plugin's `PrimaryImageResolver` registers one image per product (cover); per-image UX only makes sense once multi-image sync exists.
- Render-time background batch refresh. We render from the local cache and let the JS poll catch up. A future enhancement could pre-warm on render without breaking this design.
- "Reanalyze" button. Backend has no endpoint, and we won't introduce a fake one that secretly re-uploads.
- `image.analyzed` webhook handler. Backend has no event.
- Cross-shop / multistore semantics. The current grid is per-shop and the new columns inherit that.

## Decisions

### D1 — Persist the aggregate on `ps_qamera_product_link`, do not introduce a per-image table

**Choice:** add four nullable columns to `ps_qamera_product_link`:
- `analysis_status` `ENUM('pending','processing','described','error','partial') NULL`
- `analysis_described_count` `INT UNSIGNED NULL`
- `analysis_total_count` `INT UNSIGNED NULL`
- `analysis_refreshed_at` `DATETIME NULL`

**Alternative considered:** new `ps_qamera_product_image` table mirroring upstream `ProductImageDtoSchema`.

**Why aggregate-only wins for v1:**

- `PrimaryImageResolver` registers exactly one image per product link today (the cover). The "aggregate" of one image is the image itself — a per-image table would store one row per parent and add a JOIN to every grid read for zero new information.
- The two consumers we have (badge + Generate gate) only need the aggregate.
- Migrating from "column" to "table" later is a straightforward backfill (read columns, INSERT one row per link, drop columns) and doesn't break anything in the meantime.

**Why `'partial'` is in the enum even though one-image-per-product can't produce it:** anticipates the multi-image future where some images are `described` and others are still `processing`. Coding the enum to the long-term shape costs nothing now and saves a second migration later.

### D2 — Identify the product on pull by `qamera_product_ref`, not `qamera_product_id`

**Choice:** `AnalysisStatusRefresher::refresh($idLink)` reads `qamera_product_ref` and calls `QameraApiClient::getProduct($ref)`.

**Why:**

- `qamera_product_ref` is the stable identifier the plugin owns and assigns (e.g. `ps:1:42`). It survives even if the backend `qamera_product_id` is regenerated for any reason.
- It's the same identifier the rest of the plugin uses in logs, so traces are coherent.
- The upstream endpoint accepts either (`{id_or_ref}` path param), so there is no upstream cost to either choice.

**Alternative considered:** `qamera_product_id` (the upstream UUID). Rejected — it's nullable on `ps_qamera_product_link` (column is `CHAR(36) NULL` per `Installer.php:81`), so the code path would need a null check fallback anyway.

### D3 — Per-row TTL, not global

**Choice:** an `analysis_refreshed_at` value is considered fresh iff:
- `now() - refreshed_at < 60s` for `{pending, processing, NULL}`
- `now() - refreshed_at < 3600s` for `{described, error}`

**Why per-row:**

- A `described` row is unlikely to change for the lifetime of the image. Polling it every 5s costs zero new information and burns rate-limit budget that could go to actually-pending rows.
- A `pending` row is interesting in seconds, not minutes. The short TTL ensures the JS poll's "fetch fresh" calls won't accidentally hit a cache that's already-fresh-but-stale-content.
- The TTL gate sits in `AnalysisStatusRefresher::shouldRefresh()` — a simple `now() - refreshed_at >= ttl_for($status)` predicate. The status JSON endpoint consults it; the per-row Refresh button bypasses it (operator-intentional pull).

**Alternative considered:** a single global TTL (e.g. 60s). Rejected — over-polls settled rows and under-polls in-flight ones at the same time.

### D4 — Pull triggered by JS poll + manual refresh; render reads from DB only

**Choice:** three refresh paths, no fourth:

1. **Render path** — `SyncedProductLinkLookup::listForGrid()` SELECT extends to include the four new columns. The Twig template renders the badge from those values. No API call happens during render.
2. **JS poll** — `views/js/products_grid.js` enumerates rows with `data-analysis-status` in `{pending, processing, null}`, queues them FIFO, and every 5s POSTs the first ≤10 to `GET /modules/qameraai/products/{id_link}/status`. The endpoint internally calls `AnalysisStatusRefresher`, which consults the TTL gate and either pulls or returns the cached value. The response is JSON `{analysis_status, generate_enabled, badge_label, analyzed_at}`; the JS swaps the badge + toggles the Generate button in place.
3. **Per-row Refresh button** — same endpoint, with a `?force=1` query param that bypasses the TTL. Mirrors the existing `Refresh` button in the Jobs history (`views/templates/admin/jobs_history.html.twig` precedent).

**Why not render-time async batch:** it adds an async-after-response code path with no clean abstraction in PrestaShop's Symfony Bundle, and the JS poll already covers the "stale on F5" case within 5s.

### D5 — JS poll is infinite but rate-limited; 10 rows per cycle, FIFO

**Choice:** the poller has no overall time cap. It stops iff there are zero rows in the in-flight states *currently visible on the page*. While in-flight rows exist, it processes them in FIFO order, max 10 per 5s tick.

**Why infinite:**

- A grid where 50 products are simultaneously `processing` is a real scenario (operator just bulk-synced 50 products). With 10 rows/tick, the full cycle is ~25s — well within attention budget — and any single row that flips to `described` becomes generate-able immediately on its next tick.
- The "5-minute hard cap" alternative would silently strand the operator on a stale page if the analysis takes longer (Gemini is normally seconds, but can be minutes on retry).
- The rate-limit (10/tick) caps worst-case upstream traffic at 2 RPS per open BO tab, which sits inside the 100 req/min documented limit with headroom.

**FIFO matters** because under heavy load the bottom-of-grid rows would otherwise starve. Round-robin across the visible window keeps progress evenly distributed.

**Why no per-cycle randomisation/jitter:** the upstream rate limit is per-account, not per-IP — if a second BO tab is open the two pollers will collide on multiples of 5s. The 10-row cap absorbs that. A `Math.random() * 1000` jitter would help if collisions became real; deferred until measured.

### D6 — Generate gate hardens: `qamera_image_id IS NOT NULL AND analysis_status='described'`

**Choice:** `SyncedProductLink::canGenerate()` becomes:

```php
return $this->qameraImageId !== null
    && $this->analysisStatus === 'described';
```

Per-row UI hint mapping (rendered via `title` attribute on the disabled button):

| `qamera_image_id` | `analysis_status`           | Button   | Hint                                       |
|-------------------|----------------------------|----------|--------------------------------------------|
| NULL              | (any)                      | disabled | "Sync this product first"                  |
| NOT NULL          | NULL                       | disabled | "Awaiting analysis status — refresh"       |
| NOT NULL          | `pending`                  | disabled | "Waiting for image analysis…"              |
| NOT NULL          | `processing`               | disabled | "Image is being analysed…"                 |
| NOT NULL          | `described`                | enabled  | "Generate packshots"                       |
| NOT NULL          | `error`                    | disabled | "Image analysis failed — re-sync product"  |
| NOT NULL          | `partial` (future)         | enabled  | "Generate packshots"                       |

**Why `partial` enables Generate:** the multi-image future means "at least one image is described" is sufficient to start a session against the described image. The aggregate function picks `partial` precisely when ≥1 image is `described` AND ≥1 is not. Coding the enable-path now means the multi-image enablement won't need a second `canGenerate()` migration.

### D7 — Bulk-select silently excludes non-`described` rows, flash-info counts them

**Choice:** matches the existing pattern for unsynced rows (qamera-bo-ui spec line 33-38). On bulk-select, the controller partitions selected `id_link`s into `[generatable, blocked]`; `generatable` go to the form, `blocked` count goes to a flash-info: `"3 products excluded — image analysis not yet complete"`.

The two exclusion reasons (unsynced, not-described) compose: total exclusion count = unsynced + not-described. If both nonzero, the flash is a single sentence: `"5 products excluded (2 unsynced, 3 awaiting analysis)"`.

### D8 — DTO change on `ProductImageDto` is required, not nullable

**Choice:** `ProductImageDto` gains:

```php
public readonly string $analysisStatus,   // 'pending'|'processing'|'described'|'error'
public readonly ?string $analyzedAt,      // ISO8601 or null
```

Both required (not optional with default). Missing `analysis_status` on decode → `ValidationException`.

**Why required:** the backend invariably returns both fields per `ProductImageDtoSchema:813-814`. If a response is missing them, the backend regressed and a hard exception is the right surfacing — better than silently defaulting to `pending` and confusing every consumer downstream.

**Contract test fixture:** `tests/Contract/Fixtures/products-detail.json` updated to include both fields on every `images[]` entry. Bumping `_commit` header to the post-PR-204 hash.

### D9 — Aggregate function

**Choice:** the reduce from `images[].analysis_status[]` to one aggregate column follows this order (earliest match wins):

```
any 'error'        AND no 'described'    → 'error'
any 'pending'      OR  any 'processing'  →
   any 'described' present               → 'partial'      (only multi-image)
   no 'described' present                → worst-in-flight ('pending' if any pending else 'processing')
all 'described'                          → 'described'
no images at all                         → NULL  (treated as "no analysis cache yet")
```

`analysis_described_count` and `analysis_total_count` are populated unconditionally so the tooltip can render `"3 of 4 images ready"` once multi-image is real.

### D10 — Migration is reversible and idempotent

**Choice:** `Installer::migrateProductLinkAnalysisColumns()` checks each column's presence via `INFORMATION_SCHEMA.COLUMNS` and emits `ALTER TABLE ADD COLUMN IF NOT EXISTS ...` for missing ones. Identical pattern to the existing `migratePackshotLinkSchema()` (per `Installer.php:107-111` comment).

Fresh-install path: the `CREATE TABLE` in `Installer::createTables()` includes the four columns by default.

Uninstall path: existing `dropTables()` already drops `ps_qamera_product_link`. No new uninstall hook needed.

**Rollback:** issue a chore commit that DROPs the four columns. No data loss (cache only).

### D11 — Capability ownership: `AnalysisStatusRefresher` lives under `product-image-sync`, not a new capability

**Choice:** put the new service and the DB columns under `product-image-sync` capability rather than create `analysis-status-cache`.

**Why:** `product-image-sync` already owns `ps_qamera_product_link` writes (`ProductSnapshotWriter`) and the upstream image registration flow. Analysis status is part of "what we know about the synced image's lifecycle on the backend" — same capability boundary, smaller surface area for reviewers. A new capability for one service + four columns is over-split.

The BO-side pieces (controller, template, JS, JSON endpoint) cleanly belong to `qamera-bo-ui` as the proposal already says.

## Risks / Trade-offs

- **Risk:** Browser tab left open overnight on a grid with 1 in-flight row → 17280 polls/day, all to one endpoint that returns the same cached value. **Mitigation:** TTL gate inside `AnalysisStatusRefresher` short-circuits without hitting upstream when the row's last refresh is fresh; the BO endpoint returns a `Cache-Control: private, max-age=5` header so even the browser → BO leg can be cached by the JS layer if we ever need it. Browser-leg cost is negligible (~$0 — same server).

- **Risk:** Upstream rate-limit headroom shrinks when several BO tabs open simultaneously. **Mitigation:** 10-rows-per-tick across all tabs of one account is still 2 RPS × N_tabs ≤ 100 req/min for N_tabs ≤ ~3. If we ever observe collisions, add `BroadcastChannel` coordination across tabs (out of scope for v1).

- **Risk:** A `pending` row that stays `pending` forever (upstream worker crashed pre-PR-204 deploy) would poll until the BO tab closes. **Mitigation:** acceptable for v1 — the polling cost is 1 endpoint hit / 5s / tab and the operator notices the badge never moves. A future enhancement could escalate to an in-row "Reset analysis state" affordance once the backend supports it.

- **Trade-off:** persisting an aggregate (vs. always pulling) means the badge can lag the true upstream state by up to the TTL. **Why we accept it:** the alternative (pull on every render) costs N round-trips per page load, and the TTL is short enough (60s for in-flight) that operator perception is "live".

- **Trade-off:** the DTO change is technically a breaking decode contract — any external caller of `ProductImageDto` that doesn't decode `analysis_status` is now seeing a required field. **Why we accept it:** the only consumer is the plugin itself, and forcing the field surface flushes out any caller that was using a stale fixture.

- **Risk:** Multistore — `ps_qamera_product_link` is keyed by `(id_product, id_shop)`, but `AnalysisStatusRefresher` looks up by `qamera_product_ref`, which is unique across shops. If two shops happen to register the same upstream product (different installs, different refs), no collision — `qamera_product_ref` is shop-scoped via the prefix. No new multistore risk introduced.

## Migration Plan

1. **Schema migration** (`Installer::migrateProductLinkAnalysisColumns()`): emit `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` for each of the four new columns. Runs on module upgrade. Idempotent.
2. **Fresh-install** (`Installer::createTables()`): `CREATE TABLE` includes the four columns from the start.
3. **Bump module version** in `qameraai.php` so PrestaShop's upgrader runs the migration hook.
4. **Deploy** the module zip. No backend coordination required — backend PR #204 is already in production.
5. **Smoke** (manual, operator-driven): sync 1 product → open BO Products grid → confirm badge shows `pending`/`processing` → wait ≤30s → confirm badge flips to `described` and Generate becomes enabled.
6. **Rollback** (if needed): downgrade the module to the previous version. The four new columns are left in place (idempotent additions). Old code ignores them. No data loss.

## Open Questions

None blocking implementation. All resolved during `/opsx:explore` and documented above. Items deferred to future changes (multi-image sync, reanalyze, image webhook) are captured in proposal `Out of scope`.
