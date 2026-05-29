## ADDED Requirements

### Requirement: Jobs status is reconcilable by pulling upstream job state

The module SHALL provide a `JobsStatusRefresher` service that, given a local
`ps_qamera_packshot_job` row, pulls the authoritative job state from upstream
`GET /jobs/{id}` and reconciles the local row. The webhook delivery path remains the
primary updater; this pull is a fallback for missing/failed deliveries.

The refresher SHALL:

- Apply a per-row TTL gate keyed on `last_synced_at` and status: in-flight statuses
  (`pending`, `in_progress`, `retry_pending`, NULL) refresh after 60s; settled statuses
  (`completed`, `failed`, `cancelled`, `expired`) after 3600s; a NULL `last_synced_at`
  always pulls. A `force` flag bypasses the gate.
- Map the upstream `JobStatusSchema` value to the local `ps_qamera_packshot_job.status`
  enum: `pending→pending`, `in_progress→in_progress`, `retry_pending→in_progress`,
  `completed→completed`, `failed→failed`, `cancelled→cancelled`, `expired→cancelled`,
  unknown→`pending`.
- Persist the mapped status, `outputs[0].url` (when present), and the upstream
  `error` (en-preferred `message_i18n`, else `code`) into `last_error_message`, plus
  `last_synced_at=NOW()`, via the existing `PackshotJobRepository` write path.
- NEVER let an upstream `ApiException` bubble: log at severity 2 and return the
  last-known-good values with a populated `refreshError`.

#### Scenario: Settled job within TTL is served from cache without an upstream call
- **GIVEN** a `completed` row whose `last_synced_at` is 10 minutes ago
- **WHEN** the refresher runs without `force`
- **THEN** no `GET /jobs/{id}` call is made and the cached status is returned

#### Scenario: In-flight job past TTL pulls and reconciles
- **GIVEN** an `in_progress` row whose `last_synced_at` is 2 minutes ago
- **WHEN** the refresher runs
- **THEN** `GET /jobs/{id}` is called and the row is updated with the mapped status,
  `outputs[0].url`, and `last_synced_at=NOW()`

#### Scenario: Upstream failure is non-blocking
- **WHEN** `GET /jobs/{id}` raises an `ApiException`
- **THEN** the refresher returns the row's last-known status with `refreshError` set and
  the local row is left unchanged
