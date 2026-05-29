## ADDED Requirements

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
