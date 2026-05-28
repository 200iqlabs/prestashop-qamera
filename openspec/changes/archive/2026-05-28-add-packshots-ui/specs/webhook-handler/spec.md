## ADDED Requirements

### Requirement: Job-event handlers update the local packshot_job table by qamera_job_id

The `JobCompletedHandler`, `JobFailedHandler`, `JobRetriedHandler`, and `JobCancelledHandler` SHALL each
accept an additional dependency `PackshotJobUpdater` and, in addition to their existing side effects on
`ps_qamera_packshot_link` and the product-link heartbeat, SHALL upsert a row in `ps_qamera_packshot_job`
keyed on `qamera_job_id` extracted from the payload.

Status mapping from event type to `ps_qamera_packshot_job.status`:

| Event type      | Status set       |
|-----------------|------------------|
| `job.completed` | `completed`      |
| `job.failed`    | `failed`         |
| `job.cancelled` | `cancelled`      |
| `job.retried`   | `in_progress`    |

Additional payload-driven updates:

- `output_url`, `output_url_expires_at` set when present on `job.completed`
- `last_error_message` set when present on `job.failed`
- `last_synced_at` set to `gmdate('Y-m-d H:i:s')` on every successful handle

Unknown payload statuses (e.g. an unannounced upstream addition) SHALL be mapped to `pending` and logged at
WARNING level with the unknown value, instead of throwing. The handler MUST continue to return success
(ACK 200) so the webhook delivery is not retried indefinitely.

#### Scenario: job.completed updates an existing pending row to completed

- **GIVEN** a `ps_qamera_packshot_job` row exists with `qamera_job_id='j1'`, `status='pending'`
- **WHEN** a verified `job.completed` delivery arrives with `payload.job_id='j1'`,
  `payload.output_url='https://...'`, `payload.output_url_expires_at='2026-06-01T00:00:00Z'`
- **THEN** the row's `status` is `'completed'`, `output_url` and `output_url_expires_at` are populated,
  `last_synced_at` is set, `last_error_message` is left NULL

#### Scenario: job.failed records error message and flips status

- **GIVEN** a row exists with `status='pending'`
- **WHEN** `job.failed` arrives with `payload.error.message='quota exceeded'`
- **THEN** the row's `status` is `'failed'` and `last_error_message='quota exceeded'`

#### Scenario: Webhook arrives before submitter persisted (race condition)

- **GIVEN** no row exists for `qamera_job_id='j1'`
- **AND** the payload carries `payload.packshot_external_ref='ps:1:42:packshot:<uuid>'` and parseable
  `external_ref='ps:1:42'`
- **WHEN** the handler runs
- **THEN** a new `ps_qamera_packshot_job` row is inserted with `qamera_job_id='j1'`, FK resolved by
  looking up `(id_shop=1, id_product=42)` in `ps_qamera_product_link`, status set per the event type
- **AND** the handler emits a log entry at INFO level noting `pre_submit_webhook_upsert`

#### Scenario: Unknown payload status is mapped to pending with a warning

- **GIVEN** a `job.*` payload carries an unrecognised `status='paused'`
- **WHEN** the handler updates the row
- **THEN** the row's `status` is `'pending'`
- **AND** a WARNING-level log entry records the unknown status value
- **AND** the handler returns success (200 ACK)
