# qamera-bo-ui Specification

## Purpose

Back-office (admin) UI surfaces for the Qamera AI module: the Products grid that lists synced product-link rows with bulk-select and per-row generate actions, the Generate form that collects session_config + subjects and posts to the submitter, and the Jobs history page that lists local `ps_qamera_packshot_job` rows with status filtering and cursor pagination. This capability owns Twig templates under `views/templates/admin/`, vanilla JS assets under `views/js/`, and the BO controllers that render them; it consumes services from `packshot-jobs` (submitter, cost calculator, repository), `qamera-api-client` (reference data via the cache decorator), and `prestashop-module-bootstrap` (admin tabs). No build step, no NPM dependency, no React/Vue — jQuery and Bootstrap 4 only.
## Requirements
### Requirement: Products grid lists synced rows with bulk-select and generate actions

A BO controller `ProductsGridController` SHALL render at `GET /modules/qameraai/products` a paginated grid
of `ps_qamera_product_link` rows joined with `ps_product_lang` for the localised product name. Columns
SHALL include: checkbox (bulk-select), thumbnail, product name, sync status, **analysis status badge**,
last_synced_at, per-row "Generate" action button, per-row "Refresh analysis" action button.

Rows whose `qamera_asset_id IS NULL` SHALL render with the "Generate" action disabled and a hover hint
"Sync this product first". Rows whose `qamera_asset_id IS NOT NULL` but whose `analysis_status` is NOT
`described` (incl. NULL / `pending` / `processing` / `error`) SHALL render with the "Generate" action
disabled and a hover hint matching the current analysis state (`"Waiting for image analysis…"` for
`pending` / NULL, `"Image is being analysed…"` for `processing`, `"Image analysis failed — re-sync product"`
for `error`).

Bulk actions on a selection containing any non-generatable row (unsynced OR not-described) SHALL exclude
those rows silently and emit a flash-info naming the per-reason counts. When both reasons contribute,
the flash SHALL combine them into a single sentence of the form `"N products excluded (X unsynced, Y awaiting analysis)"`;
when only one reason contributes, the flash SHALL drop the parenthetical breakdown.

#### Scenario: Synced and analysed row shows enabled Generate button

- **GIVEN** a `ps_qamera_product_link` row with non-NULL `qamera_asset_id`, `status='registered'`, and `analysis_status='described'`
- **WHEN** the grid renders
- **THEN** the row's "Generate" button is enabled and links to the generate form pre-seeded with this product

#### Scenario: Unsynced row shows disabled Generate button with hint

- **GIVEN** a row with `qamera_asset_id IS NULL`
- **WHEN** the grid renders
- **THEN** the "Generate" button is disabled
- **AND** the row carries an accessible title attribute "Sync this product first"

#### Scenario: Synced but not-described row disables Generate with analysis-specific hint

- **GIVEN** a row with non-NULL `qamera_asset_id` and `analysis_status='processing'`
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

### Requirement: Generate form posts session_config + subjects to the submitter

A controller `GenerateFormController` SHALL render a form (modal or full page) that collects:

- One subject per selected product (pre-populated, MVP does not split a product into multiple subjects)
- `ai_model` dropdown — required — sourced from cached `/ai-models`
- `scenery_id` dropdown — optional — sourced from cached `/sceneries`, filters out `status='archived'`
- `model_id` (mannequin) dropdown — optional — sourced from cached `/models`
- `preset_id` dropdown — optional — sourced from cached `/presets`, shows `credit_cost` as label suffix
- `aspect_ratio` radio/dropdown — required — sourced from cached `/aspect-ratios`, default = entry where
  `default=true`
- `images_count` number input — required, range 1–50, default 4
- `suggestions` textarea — optional, max 2000 chars
- Live pre-flight cost display — updates client-side on every field change via the cost calculator

On `POST`, the controller SHALL validate `ai_model` is present (server-side, even if JS disabled the
submit button) and `subjects.length <= 100` (chunking handled by submitter), then call
`PackshotJobSubmitter::submit()`. On success it SHALL flash a success message naming the order_id(s) and
redirect to the jobs history page. On `ApiValidationException`, it SHALL re-render the form with
field-level error messages and NO redirect. On other API exceptions it SHALL flash a generic error and
re-render the form with inputs preserved.

#### Scenario: Successful submission redirects to jobs history

- **GIVEN** a valid form post with 1 subject and `ai_model` selected
- **WHEN** the submitter returns successfully
- **THEN** the response is a 302 to `/modules/qameraai/jobs`
- **AND** a flash-success names the new `order_id`

#### Scenario: Missing ai_model server-side returns 200 with form error

- **GIVEN** a form post without `ai_model` (client-side validation bypassed)
- **WHEN** the controller validates input
- **THEN** the response is the form re-rendered with an error on the `ai_model` field
- **AND** no call is made to `QameraApiClient::submitJob()`

#### Scenario: ApiValidationException surfaces field-level errors

- **GIVEN** the API returns 422 with `errors: [{field:'images_count', message:'must be ≤ 50'}]`
- **WHEN** the controller catches `ApiValidationException`
- **THEN** the form re-renders with the upstream message attached to the `images_count` field

### Requirement: Jobs history lists local rows with status filter and cursor pagination

A controller `JobsHistoryController` SHALL render at `GET /modules/qameraai/jobs` a paginated list of
`ps_qamera_packshot_job` rows joined with the product name. The list SHALL be filterable by status (one of
`pending|in_progress|completed|failed|cancelled` or `all`), default sort `submitted_at DESC`. Columns SHALL
include: submitted_at, product name, status badge, ai_model, aspect_ratio, output thumbnail (when
`output_url` is set), last_error_message snippet (when set).

A per-row "Refresh" button SHALL call `QameraApiClient::getJob($qameraJobId)` and update the local row.
A per-row "Re-mint URL" button SHALL appear when `output_url_expires_at` is in the past, and call
`QameraApiClient::getJobRefreshUrl()` (out of scope for MVP — button hidden in MVP, requirement reserved
for Phase 4.4).

#### Scenario: Default page shows all statuses sorted newest first

- **WHEN** the operator opens `/modules/qameraai/jobs` with no filter
- **THEN** the grid lists rows from `ps_qamera_packshot_job` ordered by `submitted_at DESC`
- **AND** the status filter dropdown is set to "All"

#### Scenario: Status filter narrows the list

- **GIVEN** the operator selects status=`failed` from the filter
- **THEN** only rows with `status='failed'` appear
- **AND** `last_error_message` is rendered for each

### Requirement: BO templates use Twig and translation domain Modules.Qameraai.Admin

All BO templates for this change SHALL be Twig (`.html.twig`), located under `views/templates/admin/`. All
operator-visible strings SHALL go through Symfony's `trans` filter with domain `Modules.Qameraai.Admin`. PL
is the primary translation; EN is the fallback. No hardcoded operator-language strings in PHP or Twig.

#### Scenario: All BO strings are translatable

- **WHEN** any template renders
- **THEN** every operator-visible string passes through `{{ '...' | trans({}, 'Modules.Qameraai.Admin') }}`
  or the Symfony equivalent

### Requirement: BO client-side code uses vanilla JS + jQuery + Bootstrap 4 only

JavaScript for the generate form (cost recalc, dynamic subject disable, max-subjects guard) SHALL be plain
ES5/ES6 in `views/js/generate_form.js`, loaded via the BO controller. The module SHALL NOT introduce any
build step, NPM dependency, React, Vue, or other framework. jQuery and Bootstrap 4 are available globally in
PS admin and may be used.

#### Scenario: No node_modules or build artifacts in repo

- **WHEN** the change ships
- **THEN** the repository contains no `package.json`, `node_modules/`, or bundled JS artifacts
- **AND** all JS sits as readable source files under `views/js/`

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

### Requirement: Jobs history reflects live job state without a manual reload

The Jobs history view SHALL keep in-flight job rows current via a client-side poll plus a
per-row Refresh action, mirroring the Products grid's analysis-status refresh.

A BO endpoint `JobStatusController::statusAction` SHALL serve
`GET /qameraai/jobs/{jobId}/status` (optional `?force=1`) and return JSON with the row's
reconciled `status`, presentation primitives (`badge_class`, `badge_label`), `output_url`,
`last_error_message`, an `in_flight` boolean, and an optional `refresh_error`. It SHALL
return 404 when no local row matches `jobId` and 500 on a DB error. `?force=1` bypasses the
refresher's TTL gate.

The Jobs history table SHALL render each row with a `data-job-id` and a status badge carrying
`data-job-status`, an output cell updatable in place, and a per-row Refresh button. Client JS
SHALL auto-poll only rows whose status is in-flight (`pending`, `in_progress`, `retry_pending`)
on a 5s interval, batched FIFO at ≤10 rows per cycle, dropping a row after 5 consecutive
failures, and SHALL stop when no in-flight rows remain. On a successful response it SHALL update
the status badge and, when the job completed, render the output thumbnail — without a page reload.

#### Scenario: Completed job appears without reload
- **GIVEN** a row rendered as `in_progress`
- **WHEN** the upstream job completes and the next poll tick reads the status endpoint
- **THEN** the badge flips to `completed` and the output thumbnail appears in place

#### Scenario: Per-row Refresh forces an immediate pull
- **WHEN** the operator clicks a row's Refresh button
- **THEN** the endpoint is called with `force=1`, bypassing the TTL gate, and the row updates

#### Scenario: Settled rows are not polled
- **GIVEN** all visible rows are `completed` / `failed` / `cancelled`
- **THEN** no poll loop runs

### Requirement: Dedicated "Packshots — review" back-office view

The module SHALL expose a back-office view listing `ps_qamera_packshot_review` rows with `voting='pending'`, each showing the preview image (`asset_url`), the localized product name, and Accept / Reject affordances. Accept/Reject SHALL invoke the vote path (`QameraApiClient::acceptJob`/`rejectJob` keyed on `qamera_job_id`) and, on success, remove the row from the pending list. The view is the voting surface only — it does not submit jobs.

#### Scenario: Pending packshots are listed with vote affordances
- **GIVEN** two `ps_qamera_packshot_review` rows with `voting='pending'`
- **THEN** the view renders both with a thumbnail, product name, and ✓/✗ controls

#### Scenario: Accepting removes the row from the pending list
- **WHEN** the operator accepts a row and the API returns 2xx
- **THEN** the row's local `voting` becomes `accepted` and it no longer appears in the pending list

### Requirement: Products grid splits Generate into two gated actions

The Products grid SHALL offer two actions per row:
- **Generate packshot** (stage 1) — enabled iff `qamera_asset_id` is present AND `analysis_status='described'` (the existing Generate-readiness gate);
- **Generate photo-shoot** (stage 4) — enabled iff the row's `product_ref` has at least one `ps_qamera_packshot_review` row with `voting='accepted'` (the grid JOINs the review table for this signal).

A row with a pending (un-accepted) packshot SHALL render "Generate photo-shoot" disabled with a hint to accept a packshot first. The gate is enforced client-side regardless of the server `PLUGIN_PHOTO_SHOOT_GATE_ENABLED` flag.

#### Scenario: Synced+described product can generate a packshot
- **GIVEN** a row with `qamera_asset_id` set and `analysis_status='described'`
- **THEN** "Generate packshot" is enabled

#### Scenario: Accepted packshot enables photo-shoot
- **GIVEN** a row whose `product_ref` has a `ps_qamera_packshot_review` row `voting='accepted'`
- **THEN** "Generate photo-shoot" is enabled

#### Scenario: Pending packshot keeps photo-shoot disabled with a hint
- **GIVEN** a row whose only review state is `voting='pending'`
- **THEN** "Generate photo-shoot" is disabled with a hint to generate+accept a packshot first

