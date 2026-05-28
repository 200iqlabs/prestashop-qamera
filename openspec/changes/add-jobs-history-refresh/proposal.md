## Why

The Jobs history sub-tab (introduced in Phase 4.3 `add-packshots-ui`) reflects only webhook-delivered state. In the live smoke (2026-05-28) the operator saw 4 submitted jobs sitting in `pending` for the entire session because the dev environment cannot receive Qamera webhooks (no public URL) — the rows would have updated had webhooks reached the install. Even in production-like deploys with webhook delivery, transient delivery failures (network blips, reverse-proxy outages, signature-validation regressions on the receiving side) leave the operator blind: the Qamera-side job has long completed but the plugin row says `pending` forever and there is no affordance to force a reconciliation. Today the only recovery is direct DB inspection or re-submitting the job (which costs credits a second time).

Adjacent precedent: `add-analysis-status-surfacing` shipped per-row Refresh on the products grid for the same class of problem (cache desync vs upstream truth). The same pattern fits Jobs history.

## What Changes

- Per-row "Refresh" button on the Jobs history table that calls a new BO endpoint `GET /modules/qameraai/jobs/{id_job}/status?force=1` → service pulls fresh state from upstream `GET /jobs/{id}` → updates `ps_qamera_packshot_job` row → returns JSON for in-place row update.
- Top-of-table "Refresh stuck jobs" bulk button that finds all rows in `{queued, processing}` older than 5 minutes and re-pulls each (rate-limited, batched, identical poll-cycle semantics to `views/js/products_grid.js` from the analysis-status change).
- New service `JobsStatusRefresher` matching the shape of `AnalysisStatusRefresher`: per-row TTL gate (60s for in-flight, 3600s for settled), sanitises upstream errors without bubbling, logs warnings via `PrestaShopLoggerWrapper`.
- JS auto-poll for visible rows in `{queued, processing}`: 5s interval, FIFO ≤10/cycle, infinite-while-in-flight — copy the products-grid JS approach exactly rather than reinventing it. Extract the shared bits into `views/js/qamera_poll.js` so both grids consume one implementation.
- Last-error column on the row (already in the schema as `last_error_message`) becomes user-visible — today it is persisted but never rendered.

## Capabilities

### New Capabilities

(none — extends existing capability)

### Modified Capabilities

- `qamera-bo-ui`: Jobs history table gains Refresh button(s), auto-poll behaviour, and a visible last-error column; shared poll JS extracted into a reusable helper.
- `packshot-jobs`: new requirement on `JobsStatusRefresher` service contract (TTL gate, sanitisation, write-back into `ps_qamera_packshot_job`); the existing webhook-driven update path stays primary, refresh is a fallback.

## Impact

- **Code**: new `src/Packshot/JobsStatusRefresher.php`, new `src/Controller/Admin/JobStatusController.php`, new route in `config/routes.yml`, new wiring in `config/services.yml`, edits to Jobs history Twig + JS, extraction of shared poll helper into `views/js/qamera_poll.js`.
- **Schema**: none (all needed columns already exist on `ps_qamera_packshot_job`).
- **Upstream contract**: uses already-shipped `GET /jobs/{id}` — no API change.
- **Lower priority than the other four queued changes**: this is operator quality-of-life and is partially mitigated by `add-packshot-acceptance-flow` redesigning the Generate flow anyway. Defer until the four above are merged unless an in-prod operator hits the desync class.
- **Out of scope**: server-side cron-driven reconciliation of stale jobs (cron infra not yet established in the module; per-row pull is adequate for v1), surfacing webhook delivery health (the `ps_qamera_webhook_delivery` table already supports this but its UI is a separate scope).
