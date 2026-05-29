# Tasks — add-jobs-history-refresh

## 1. Refresher service (packshot-jobs)

- [x] 1.1 `JobRefreshResult` value object (status, outputUrl, outputUrlExpiresAt, lastErrorMessage, lastSyncedAt, ?refreshError).
- [x] 1.2 `JobsStatusRefresher` (mirrors `AnalysisStatusRefresher`): TTL gate on `last_synced_at`+status (60s in-flight / 3600s settled / NULL→always); `getJob()` pull; upstream→local status map (D3); `outputs[0].url`/`error.messageI18n` extraction; write-back via `PackshotJobRepository::upsertFromWebhook`; sanitise + never bubble ApiException (log sev 2, return cached + refreshError). `now()`/`nowTimestamp()` protected seams.

## 2. BO endpoint (qamera-bo-ui)

- [x] 2.1 `JobStatusController::statusAction(jobId, ?force)` → JSON `{qamera_job_id, status, badge_class, badge_label, output_url, last_error_message, in_flight, refresh_error?}`; 404 when row absent; 500 on DB error.
- [x] 2.2 Route `_qameraai_admin_job_status` (`GET /qameraai/jobs/{jobId}/status`, jobId `[A-Za-z0-9._-]+`); services.yml wiring for the refresher (public, all-service deps).

## 3. View + JS (qamera-bo-ui)

- [x] 3.1 `jobs_history.html.twig`: per-row `data-job-id`, status badge `data-job-status` + `js-qameraai-job-badge`, output cell `js-qameraai-job-output`, per-row Refresh button, status-URL `<script>` + `jobs_history.js` asset tag. (`JobsHistoryController` passes `status_url_template` + `js_asset_url`.)
- [x] 3.2 `views/js/jobs_history.js`: auto-poll in-flight rows (5s, FIFO ≤10/cycle, ≤5 fails/row), per-row Refresh (force), in-place badge + output-thumbnail update (thumbnail built via DOM nodes, not innerHTML — XSS-safe). (D4: dedicated, not shared extraction.)

## 4. Tests + static

- [x] 4.1 `JobsStatusRefresherTest` (7 tests): TTL gate (settled cached / in-flight pulls / force bypass / NULL pulls), status map (retry_pending→in_progress, expired→cancelled), output+error extraction + write-back arg capture, ApiException → cached + refreshError + no write.
- [x] 4.2 PHPCS clean; PHPUnit 376/376 green (worktree off main); PHPStan in CI. (8.2/8.3 mirror 8.1.)

## 5. Smoke (operator-driven)

- [ ] 5.1 Submit a job → Jobs history shows it in-flight → row flips to completed + thumbnail without reload (auto-poll); per-row Refresh forces an immediate pull.
