# Design — add-jobs-history-refresh

Make the Jobs history sub-tab reflect live job state without a manual page reload,
mirroring the analysis-status machinery already shipped for the Products grid
(`add-analysis-status-surfacing`). The webhook remains the primary update path;
this is a pull-based fallback for when deliveries never arrive (dev with no public
URL, transient delivery failures).

## Decisions

### D1: Reuse `PackshotJobRepository::upsertFromWebhook` for write-back — RESOLVED

The repository already exposes an UPDATE-by-`qamera_job_id` path
(`upsertFromWebhook(PackshotJobWebhookUpdate)`) that sets
`status / output_url / output_url_expires_at / last_error_message / last_synced_at`.
A `GET /jobs/{id}` pull maps cleanly onto the same fields, so `JobsStatusRefresher`
builds a `PackshotJobWebhookUpdate` (UPDATE-only — no fallback insert; the row always
exists since the refresher is driven from an existing grid row) and routes through the
repository. No new SQL, no new table, no duplicated escape/cast logic.

### D2: New `JobsStatusRefresher` service mirrors `AnalysisStatusRefresher` — RESOLVED

Same shape and seams: per-row TTL gate, upstream-error sanitisation that never bubbles
(logs at severity 2, returns last-known-good + `refreshError`), `now()`/`nowTimestamp()`
protected seams for tests. Constructor takes the repository + `QameraApiClient` +
`PrestaShopLoggerWrapper` (NOT a raw `Db` — the repository owns SQL). `class`, not
`final`, so tests can subclass the clock/client.

TTL gate keyed on `last_synced_at` + status:
- in-flight (`pending`, `in_progress`, `retry_pending`, NULL) → 60s
- settled (`completed`, `failed`, `cancelled`, `expired`) → 3600s
- NULL `last_synced_at` → always pull

### D3: Upstream→local status map — RESOLVED

Upstream `JobStatusSchema` = `pending | in_progress | completed | failed | retry_pending
| cancelled | expired`. Local `ps_qamera_packshot_job.status` enum =
`pending | in_progress | completed | failed | cancelled`. Map:

| upstream        | local         |
|-----------------|---------------|
| pending         | pending       |
| in_progress     | in_progress   |
| retry_pending   | in_progress   |
| completed       | completed     |
| failed          | failed        |
| cancelled       | cancelled     |
| expired         | cancelled     |
| (unknown)       | pending       |

`expired` → `cancelled` (terminal, not a generation error). `retry_pending` → `in_progress`
(still in flight). `JobDto.error` (an `ErrorBody`) → `last_error_message` via `messageI18n`
(en-preferred) then `code`. `outputs[0].url` → `output_url`.

### D4: Dedicated `jobs_history.js`, NOT a shared-helper extraction — RESOLVED (deviation from proposal)

The proposal floated extracting the products-grid poll into a shared `qamera_poll.js`.
Rejected for v1: refactoring the **working** `products_grid.js` to share code carries
regression risk on a shipped feature for no user-facing gain. `jobs_history.js` carries
its own small poll loop (same semantics: 5s interval, FIFO ≤10/cycle, infinite-while-in-flight,
≤5 consecutive failures per row before drop). Extraction can be a separate refactor later
if a third consumer appears.

### D5: Scope cuts for v1 — RESOLVED

- **IN:** per-row Refresh button (force pull), JS auto-poll of in-flight rows, status-badge +
  output-thumbnail in-place update, the `JobsStatusRefresher` service + JSON endpoint.
- **DEFER:** the bulk "Refresh stuck jobs" button (per-row Refresh + auto-poll already cover
  the operator's need; bulk is additive and can follow). Logged here so it is not a silent cut.
- **Already present:** `last_error_message` is already rendered in `jobs_history.html.twig`
  (lines 62-65) — no work needed there.

## Files

- new `src/Packshot/JobsStatusRefresher.php` + `src/Packshot/JobRefreshResult.php`
- new `src/Controller/Admin/JobStatusController.php`
- new route `_qameraai_admin_job_status` in `config/routes.yml`
- wiring in `config/services.yml` (refresher: repository + client + logger)
- `views/templates/admin/jobs_history.html.twig`: per-row `data-job-id` / `data-job-status`,
  output cell id, Refresh button, status-URL `<script>` + asset tag
- new `views/js/jobs_history.js`
- tests: `tests/Unit/Packshot/JobsStatusRefresherTest.php`

## Out of scope

Cron-driven server-side reconciliation (no cron infra in the module); webhook-delivery-health
UI; the shared-poll-JS extraction (D4).
