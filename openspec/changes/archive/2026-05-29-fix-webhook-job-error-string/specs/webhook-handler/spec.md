## MODIFIED Requirements

### Requirement: Job-event handlers update the local packshot_job table by qamera_job_id

The `JobCompletedHandler`, `JobFailedHandler`, `JobRetriedHandler`, and `JobCancelledHandler` SHALL each upsert a row in `ps_qamera_packshot_job` keyed on `qamera_job_id` taken from `payload.job.id`, and SHALL refresh the `ps_qamera_product_link` heartbeat for the `(shopId, productId)` parsed from `payload.job.product_ref`. Handlers SHALL NOT write to `ps_qamera_packshot_link` (that table is removed — see webhook-event-dispatch).

Status mapping (unchanged):

| Event           | Status set    |
|-----------------|---------------|
| `job.completed` | `completed`   |
| `job.failed`    | `failed`      |
| `job.cancelled` | `cancelled`   |
| `job.retried`   | `in_progress` |

Additional payload-driven updates:

- `output_url` set from `payload.outputs[0].url` when present on `job.completed` (`output_url_expires_at` set when the output carries an expiry)
- `last_error_message` set from `payload.job.error` on `job.failed`. The extractor SHALL tolerate **both** observed wire shapes: a non-empty **string** (the live-confirmed shape for plugin-job validation/generation failures) is used verbatim; an **object** `{message_i18n|message|code}` is reduced to a message string via the locale-preference order (requested locale → `en` → any → `code`). Anything else (null/empty/number) yields no message (`last_error_message` stays NULL). The persisting updater truncates the resulting string to the column's TEXT capacity.
- `last_synced_at` set to `gmdate('Y-m-d H:i:s')` on every successful handle

Unknown payload `job.status` values SHALL map to `pending` and log at WARNING, not throw; the handler MUST still ACK 200.

#### Scenario: job.completed updates an existing pending row to completed
- **GIVEN** a `ps_qamera_packshot_job` row with `qamera_job_id='j1'`, `status='pending'`
- **WHEN** a verified `job.completed` arrives with `payload.job.id='j1'` and `payload.outputs[0].url='https://…'`
- **THEN** the row's `status='completed'`, `output_url` is populated from `outputs[0].url`, `last_synced_at` is set, `last_error_message` stays NULL

#### Scenario: job.failed records a STRING job.error verbatim and flips status
- **GIVEN** a row with `status='pending'`
- **WHEN** `job.failed` arrives with `payload.job.error='PLUGIN_JOB_MISSING_CATALOG_ENTRY: asset_id … has no matching product_packshots row'` (a plain string)
- **THEN** the row's `status='failed'` and `last_error_message` equals that string (truncated only if it exceeds TEXT capacity)

#### Scenario: job.failed records an OBJECT job.error from its message and flips status
- **GIVEN** a row with `status='pending'`
- **WHEN** `job.failed` arrives with `payload.job.error.message='quota exceeded'`
- **THEN** the row's `status='failed'` and `last_error_message='quota exceeded'`

#### Scenario: job.failed with an empty or absent job.error leaves the message NULL
- **GIVEN** a row with `status='pending'`
- **WHEN** `job.failed` arrives with `payload.job.error` absent (or an empty string)
- **THEN** the row's `status='failed'` and `last_error_message` is NULL

#### Scenario: Webhook arrives before submitter persisted (race condition)
- **GIVEN** no row exists for `qamera_job_id='j1'`
- **AND** the payload carries `payload.job.product_ref='ps:1:42'` and `payload.job.order_id='ord-1'`
- **WHEN** the handler runs
- **THEN** a new `ps_qamera_packshot_job` row is inserted with `qamera_job_id='j1'`, FK resolved by looking up `(id_shop=1, id_product=42)` in `ps_qamera_product_link`, status per the event
- **AND** the handler emits an INFO log noting `pre_submit_webhook_upsert`

#### Scenario: Unknown payload status is mapped to pending with a warning
- **GIVEN** a `job.*` payload carries `job.status='paused'`
- **THEN** the row's `status='pending'`, a WARNING records the unknown value, and the handler ACKs 200
