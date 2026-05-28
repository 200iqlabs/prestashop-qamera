## MODIFIED Requirements

### Requirement: Products grid lists synced rows with bulk-select and generate actions

A BO controller `ProductsGridController` SHALL render at `GET /modules/qameraai/products` a paginated grid
of `ps_qamera_product_link` rows joined with `ps_product_lang` for the localised product name. Columns
SHALL include: checkbox (bulk-select), thumbnail, product name, sync status, **analysis status badge**,
last_synced_at, per-row "Generate" action button, per-row "Refresh analysis" action button.

Rows whose `qamera_image_id IS NULL` SHALL render with the "Generate" action disabled and a hover hint
"Sync this product first". Rows whose `qamera_image_id IS NOT NULL` but whose `analysis_status` is NOT
`described` (incl. NULL / `pending` / `processing` / `error`) SHALL render with the "Generate" action
disabled and a hover hint matching the current analysis state (`"Waiting for image analysis…"` for
`pending` / NULL, `"Image is being analysed…"` for `processing`, `"Image analysis failed — re-sync product"`
for `error`).

Bulk actions on a selection containing any non-generatable row (unsynced OR not-described) SHALL exclude
those rows silently and emit a flash-info naming the per-reason counts. When both reasons contribute,
the flash SHALL combine them into a single sentence of the form `"N products excluded (X unsynced, Y awaiting analysis)"`;
when only one reason contributes, the flash SHALL drop the parenthetical breakdown.

#### Scenario: Synced and analysed row shows enabled Generate button

- **GIVEN** a `ps_qamera_product_link` row with non-NULL `qamera_image_id`, `status='registered'`, and `analysis_status='described'`
- **WHEN** the grid renders
- **THEN** the row's "Generate" button is enabled and links to the generate form pre-seeded with this product

#### Scenario: Unsynced row shows disabled Generate button with hint

- **GIVEN** a row with `qamera_image_id IS NULL`
- **WHEN** the grid renders
- **THEN** the "Generate" button is disabled
- **AND** the row carries an accessible title attribute "Sync this product first"

#### Scenario: Synced but not-described row disables Generate with analysis-specific hint

- **GIVEN** a row with non-NULL `qamera_image_id` and `analysis_status='processing'`
- **WHEN** the grid renders
- **THEN** the "Generate" button is disabled
- **AND** the row carries an accessible title attribute "Image is being analysed…"

#### Scenario: Bulk action filters out unsynced and not-described rows with combined flash

- **GIVEN** the operator bulk-selects 7 rows: 4 generatable, 2 unsynced, 1 awaiting analysis
- **WHEN** they click "Generate packshots"
- **THEN** the generate form opens with 4 subjects (only generatable rows)
- **AND** a flash-info shows "3 products excluded (2 unsynced, 1 awaiting analysis)"

#### Scenario: Bulk action with single-reason exclusion drops the parenthetical

- **GIVEN** the operator bulk-selects 5 rows: 3 generatable, 2 awaiting analysis
- **WHEN** they click "Generate packshots"
- **THEN** the generate form opens with 3 subjects
- **AND** a flash-info shows "2 products excluded — awaiting analysis"

## ADDED Requirements

### Requirement: Products grid renders an analysis-status badge per row

Every grid row SHALL render an "Analysis" column with a Bootstrap-4 badge whose colour and label reflect
the row's `analysis_status` column value. The mapping SHALL be:

| `analysis_status`              | Badge class       | Label (PL primary, EN fallback) | Icon |
|--------------------------------|-------------------|---------------------------------|------|
| NULL                           | `badge-secondary` | "Czeka na analizę" / "Pending"  | ⏳   |
| `pending`                      | `badge-secondary` | "Czeka na analizę" / "Pending"  | ⏳   |
| `processing`                   | `badge-info`      | "Analizowanie…" / "Processing"  | 🔄   |
| `described`                    | `badge-success`   | "Gotowe" / "Ready"              | ✓    |
| `error`                        | `badge-danger`    | "Błąd analizy" / "Error"        | ⚠   |
| `partial` (multi-image future) | `badge-warning`   | "Częściowe" / "Partial"         | ◐    |

When `analysis_status='described'` AND `analysis_refreshed_at IS NOT NULL`, the badge SHALL carry a
`title` tooltip of the form `"Analysed at <ISO timestamp>"` rendered from `analyzed_at`.

When `analysis_total_count > 1` AND `analysis_status IN ('described','partial')`, the badge label SHALL
append `" (k of n)"` where `k=analysis_described_count` and `n=analysis_total_count` (multi-image
forward-compat — single-image v1 always shows `(1 of 1)` or omits the suffix; impl decides).

The badge container element SHALL carry `data-analysis-status="<value-or-null>"` and `data-id-link="<int>"`
attributes so the JS poll can target rows by selector.

#### Scenario: Described row renders success badge with tooltip

- **GIVEN** a row with `analysis_status='described'` and `analysis_refreshed_at='2026-05-28T10:00:00Z'`
- **WHEN** the grid renders
- **THEN** the badge element is `<span class="badge badge-success" data-analysis-status="described" data-id-link="42" title="Analysed at 2026-05-28T10:00:00Z">✓ Ready</span>` (or its translated variant)

#### Scenario: Pending row carries data-analysis-status="pending" so JS poll can target it

- **GIVEN** a row with `analysis_status='pending'`
- **THEN** the badge element carries `data-analysis-status="pending"`
- **AND** the JS poll's selector `[data-analysis-status="pending"], [data-analysis-status="processing"], [data-analysis-status="null"]` matches this row

#### Scenario: Row with NULL analysis_status renders pending badge

- **GIVEN** a row created before the analysis-status migration that has `analysis_status IS NULL`
- **WHEN** the grid renders
- **THEN** the badge renders as Pending and `data-analysis-status="null"` (literal string)

### Requirement: BO status JSON endpoint serves per-row analysis state

A controller `ProductStatusController` SHALL register at `GET /modules/qameraai/products/{idLink}/status`
with a `force` query parameter (`?force=1` to bypass the TTL gate). The endpoint SHALL:

1. Authenticate the caller as a BO employee (PS admin session cookie, same auth surface as the grid).
2. Resolve `idLink` to a `ps_qamera_product_link` row scoped to the current shop. If no row, return 404 JSON `{error: "not_found"}`.
3. Call `AnalysisStatusRefresher::refresh($row)` (TTL-gated unless `force=1`). The refresher writes to the row if it pulled fresh.
4. Return HTTP 200 JSON of shape:

```json
{
  "id_link": 42,
  "analysis_status": "described",
  "analysis_described_count": 1,
  "analysis_total_count": 1,
  "analysis_refreshed_at": "2026-05-28T10:00:00Z",
  "analyzed_at": "2026-05-28T09:59:55Z",
  "generate_enabled": true,
  "badge_class": "badge-success",
  "badge_label": "Ready",
  "badge_icon": "✓",
  "hint": null
}
```

The response carries `Cache-Control: private, max-age=5` so the BO browser tab can short-circuit
duplicate polls within a 5s window.

If the upstream pull fails (any subclass of `ApiException`), the endpoint SHALL return HTTP 200 with
the *current cached values* (whatever is in the DB) and an additional `refresh_error: "<sanitized message>"`
field so the JS layer can surface a non-blocking warning toast. The cached values are still returned
because they're better than nothing for the operator.

#### Scenario: Force refresh bypasses TTL

- **GIVEN** a row with `analysis_status='processing'` and `analysis_refreshed_at=NOW() - 10s` (within 60s TTL)
- **WHEN** the operator clicks the per-row Refresh button which calls `?force=1`
- **THEN** `AnalysisStatusRefresher` issues a `GET /products/{ref}` to the upstream regardless of TTL

#### Scenario: TTL-gated poll returns cached value without HTTP call

- **GIVEN** a row with `analysis_status='processing'` and `analysis_refreshed_at=NOW() - 10s`
- **WHEN** the JS poll calls the endpoint without `force`
- **THEN** the response is served from the cached row values; no upstream HTTP call is issued
- **AND** `analysis_refreshed_at` is unchanged

#### Scenario: Upstream failure returns cached values with refresh_error

- **GIVEN** a row with cached `analysis_status='processing'`; the upstream returns 503 for three retries
- **WHEN** the JS poll calls the endpoint with the row past its 60s TTL
- **THEN** the response carries the cached `analysis_status='processing'`, the existing `analysis_refreshed_at`, and a `refresh_error` string sanitised from the `ServerException`

#### Scenario: Unknown id_link returns 404 JSON

- **GIVEN** no row exists for `id_link=99999` in the current shop
- **WHEN** any caller hits `GET /modules/qameraai/products/99999/status`
- **THEN** the response is HTTP 404 JSON `{"error":"not_found"}`

### Requirement: Grid JS poll refreshes in-flight rows on a 5s tick with a FIFO rate limit

A vanilla-JS module `views/js/products_grid.js` SHALL run on the Products grid page only. On `DOMContentLoaded`,
it SHALL:

1. Enumerate badge elements matching `[data-analysis-status="pending"], [data-analysis-status="processing"], [data-analysis-status="null"]`.
2. Build a FIFO queue of `id_link` integers from those elements.
3. Every 5000ms, dequeue up to 10 ids and fire concurrent `fetch('/modules/qameraai/products/<id>/status')` requests.
4. For each response, update the badge element in-place: swap classes/label/icon per the response, toggle
   the row's Generate button enabled/disabled per `generate_enabled`, update the `data-analysis-status` attribute.
5. If the new status is still in-flight (`pending`/`processing`), push the id back to the tail of the queue.
6. If the new status is settled (`described`/`error`/`partial`), drop the id from the queue.
7. The poller SHALL NOT have a wall-clock timeout. It SHALL stop only when the queue is empty (zero in-flight
   rows). On page navigation away, the JS layer is disposed naturally by the browser.

The module SHALL be vanilla ES5/ES6 — no NPM, no build, no React, no Vue (per `qamera-bo-ui` capability's
"vanilla JS + jQuery + Bootstrap 4 only" requirement).

#### Scenario: Grid with 50 in-flight rows pulls 10 per tick in FIFO order

- **GIVEN** the grid loads with 50 rows in `pending` / `processing` / NULL
- **WHEN** the page has been open for 5s (one tick)
- **THEN** the JS has issued exactly 10 status requests, all targeting the first 10 ids in DOM order
- **AND** at 10s the next 10 ids have been targeted, etc.

#### Scenario: Row transitioning to described drops out of poll queue

- **GIVEN** a row at FIFO position 3 is `processing` at tick T
- **WHEN** the response at T+5s returns `analysis_status='described'`
- **THEN** the badge is updated to success, the Generate button enables, and the id is removed from the queue
- **AND** the next tick at T+10s pulls ids 4..13 (skipping the dropped id)

#### Scenario: Poller stops when queue empties

- **GIVEN** a grid that started with 3 in-flight rows, all of which have flipped to `described` within 30s
- **WHEN** the queue empties
- **THEN** the `setInterval` (or equivalent scheduler) is cleared and no further fetch requests are issued
- **AND** the poller does not restart on its own — manual Refresh on a row is the only way to re-enter the in-flight state

### Requirement: Per-row Refresh button forces a synchronous TTL-bypass pull

Every grid row SHALL render a "Refresh analysis" icon button alongside the Generate button. Clicking it SHALL:

1. Add a `disabled` attribute + spinner class to the button.
2. Fire a `fetch` to `GET /modules/qameraai/products/<id>/status?force=1`.
3. On response, update the badge + Generate button per the same logic as the JS poll.
4. Re-enable the Refresh button.

The button SHALL be visible regardless of current status — operators may want to confirm a `described`
state hasn't silently regressed, or hurry along a `pending` row.

#### Scenario: Refresh on a described row bypasses TTL

- **GIVEN** a row with `analysis_status='described'` and `analysis_refreshed_at=NOW() - 30min` (within 3600s TTL)
- **WHEN** the operator clicks Refresh
- **THEN** the endpoint is hit with `?force=1` and `AnalysisStatusRefresher` issues a fresh `GET /products/{ref}` regardless of TTL
- **AND** the badge re-renders (possibly unchanged) and `analysis_refreshed_at` is bumped to NOW()
