# webhook-event-dispatch Specification

## Purpose
TBD - created by syncing change add-webhook-event-dispatch. Update Purpose after archive.

## Requirements

### Requirement: Dispatcher routes accepted deliveries on event_type

The module SHALL expose an event dispatcher that consumes verified webhook deliveries (already persisted to `qamera_webhook_delivery` with `status='accepted'` by the webhook-handler capability) and routes each delivery to exactly one handler keyed on the `event_type` field. The dispatcher MUST be invoked synchronously, after the delivery row is persisted, and before the HTTP `200` ACK is returned. The dispatcher MUST NOT propagate exceptions to its caller — any handler failure SHALL be caught, logged at `error` level with the `delivery_id`, `event_type`, and exception class name, and swallowed.

#### Scenario: job.completed routes to the completed handler
- **WHEN** the dispatcher receives a delivery with `event_type='job.completed'`
- **THEN** the JobCompletedHandler SHALL be invoked exactly once with the parsed `WebhookEvent`
- **AND** no other handler SHALL be invoked

#### Scenario: job.failed routes to the failed handler
- **WHEN** the dispatcher receives a delivery with `event_type='job.failed'`
- **THEN** the JobFailedHandler SHALL be invoked exactly once

#### Scenario: job.cancelled routes to the cancelled handler
- **WHEN** the dispatcher receives a delivery with `event_type='job.cancelled'`
- **THEN** the JobCancelledHandler SHALL be invoked exactly once

#### Scenario: job.retried routes to the retried handler
- **WHEN** the dispatcher receives a delivery with `event_type='job.retried'`
- **THEN** the JobRetriedHandler SHALL be invoked exactly once

#### Scenario: Unknown but well-formed event type is a no-op
- **WHEN** the dispatcher receives a delivery with `event_type='job.future_kind'`
- **THEN** no handler SHALL be invoked
- **AND** the dispatcher SHALL emit a single log line at `info` level containing the `delivery_id` and `event_type`
- **AND** the dispatcher SHALL return without raising

#### Scenario: Handler exception is caught and logged
- **WHEN** a handler raises `\Throwable` during `handle()`
- **THEN** the dispatcher SHALL catch the exception
- **AND** the dispatcher SHALL emit a log line at `error` level containing the `delivery_id`, `event_type`, and the exception's class name
- **AND** the log line MUST NOT contain the exception's full message if it could include the upstream `error_message` payload field (truncate or omit), the raw payload, or any HMAC bytes
- **AND** the dispatcher SHALL return without re-raising

### Requirement: external_ref parser accepts the canonical ps prefix and rejects everything else

The module SHALL parse the `external_ref` payload field with format `ps:<shopId>:<productId>:image:<imageId>` into an immutable value containing `shopId`, `productId`, and `imageId` as positive integers. Refs that do not match this exact shape SHALL cause the parser to throw `InvalidExternalRefException`. The handler invoking the parser SHALL catch the exception, log at `warning` level, and skip the dispatch (no DB writes).

#### Scenario: Canonical ref parses to three positive integers
- **WHEN** the parser is given `'ps:1:42:image:7'`
- **THEN** it SHALL return a value with `shopId=1`, `productId=42`, `imageId=7`

#### Scenario: Non-ps prefix is rejected
- **WHEN** the parser is given `'qamera:1:42:image:7'`
- **THEN** it SHALL throw `InvalidExternalRefException`

#### Scenario: Truncated ref is rejected
- **WHEN** the parser is given `'ps:1:42'`
- **THEN** it SHALL throw `InvalidExternalRefException`

#### Scenario: Non-numeric segment is rejected
- **WHEN** the parser is given `'ps:abc:42:image:7'`
- **THEN** it SHALL throw `InvalidExternalRefException`

#### Scenario: Negative integer is rejected
- **WHEN** the parser is given `'ps:-1:42:image:7'`
- **THEN** it SHALL throw `InvalidExternalRefException`

#### Scenario: Leading or trailing whitespace is rejected
- **WHEN** the parser is given `' ps:1:42:image:7'` or `'ps:1:42:image:7 '`
- **THEN** it SHALL throw `InvalidExternalRefException`

### Requirement: job.completed upserts a ready packshot row and refreshes the product heartbeat

When the dispatcher routes a `job.completed` delivery, the JobCompletedHandler SHALL parse the payload's `external_ref` into `(shopId, productId, imageId)`, verify a `ps_qamera_product_link` row exists for `(id_shop=shopId, id_product=productId)`, then UPSERT a row in `ps_qamera_packshot_link` keyed on `qamera_packshot_id` from the payload with: `status='ready'`, `qamera_job_id=<payload.job_id>` (NULL if omitted), `id_shop=shopId`, `id_product=productId`, `qamera_packshot_ref=<deterministic ref derived from shopId, productId, qamera_packshot_id>`, `last_synced_at=NOW()`, `updated_at=NOW()`, `last_error_message=NULL`, `created_at=NOW()` on insert. After the packshot upsert succeeds, the handler SHALL refresh `ps_qamera_product_link.last_synced_at` for the same `(id_shop, id_product)` to `NOW()` WITHOUT modifying any other column on that row.

#### Scenario: Happy path: packshot row inserted, product heartbeat bumped
- **GIVEN** a `ps_qamera_product_link` row exists for `(id_shop=1, id_product=42)` with `status='registered'`, `qamera_product_id='abc-uuid'`, `last_synced_at='2026-05-27 10:00:00'`
- **AND** no row exists in `ps_qamera_packshot_link` for `qamera_packshot_id='packshot-uuid'`
- **WHEN** the handler processes a `job.completed` delivery with payload `{external_ref:'ps:1:42:image:7', packshot_id:'packshot-uuid', job_id:'job-uuid'}`
- **THEN** a new row in `ps_qamera_packshot_link` SHALL contain `qamera_packshot_id='packshot-uuid'`, `qamera_job_id='job-uuid'`, `id_shop=1`, `id_product=42`, `status='ready'`, `last_synced_at` set to the dispatch instant, `last_error_message=NULL`
- **AND** the `ps_qamera_product_link` row's `last_synced_at` SHALL be bumped to the dispatch instant
- **AND** the `ps_qamera_product_link` row's `status` SHALL remain `'registered'` and `qamera_product_id` SHALL remain `'abc-uuid'` unchanged

#### Scenario: Idempotent re-delivery updates the existing packshot row in place
- **GIVEN** a `ps_qamera_packshot_link` row exists with `qamera_packshot_id='packshot-uuid'`, `status='ready'`, `last_synced_at='2026-05-27 10:00:00'`
- **WHEN** a second `job.completed` delivery arrives for the same `packshot_id`
- **THEN** the existing row SHALL be updated in place — `status` stays `'ready'`, `last_synced_at` is bumped
- **AND** no second row SHALL be inserted

#### Scenario: External_ref points to a product the shop does not own
- **GIVEN** no `ps_qamera_product_link` row exists for `(id_shop=99, id_product=42)`
- **WHEN** the handler processes a `job.completed` delivery with `external_ref='ps:99:42:image:7'`
- **THEN** no row SHALL be inserted in `ps_qamera_packshot_link`
- **AND** no row SHALL be updated in `ps_qamera_product_link`
- **AND** a `warning` log line SHALL be emitted containing `delivery_id`, `event_type`, and `external_ref`

#### Scenario: Malformed external_ref is logged and skipped
- **WHEN** the handler processes a `job.completed` delivery with `external_ref='not-a-ref'`
- **THEN** no DB writes SHALL occur
- **AND** a `warning` log line SHALL be emitted containing `delivery_id` and `event_type`

#### Scenario: Payload missing packshot_id is logged at error
- **WHEN** the handler processes a `job.completed` delivery whose payload omits `packshot_id`
- **THEN** no DB writes SHALL occur
- **AND** an `error` log line SHALL be emitted containing `delivery_id`, `event_type`, and the missing field name

#### Scenario: DB error during upsert is swallowed
- **WHEN** the packshot upsert raises `QameraDbException`
- **THEN** no `ps_qamera_product_link` heartbeat SHALL be attempted
- **AND** an `error` log line SHALL be emitted containing `delivery_id`, `event_type`, and the exception's class name
- **AND** the handler SHALL return without raising

### Requirement: job.failed upserts a failed packshot row with sanitized error message

When the dispatcher routes a `job.failed` delivery, the JobFailedHandler SHALL UPSERT a row in `ps_qamera_packshot_link` keyed on `qamera_packshot_id` with `status='failed'`, `qamera_job_id=<payload.job_id>` (NULL if omitted), `last_synced_at=NOW()`, `updated_at=NOW()`, and `last_error_message=<payload.error_message>` truncated to fit a `TEXT` column (65535 bytes). The handler SHALL then refresh `ps_qamera_product_link.last_synced_at` as defined for `job.completed`. The `ps_qamera_product_link.status` and `qamera_product_id` SHALL NOT be modified — a downstream packshot failure does not invalidate the upstream product registration.

#### Scenario: Failed event populates last_error_message
- **GIVEN** a `ps_qamera_product_link` row exists for `(id_shop=1, id_product=42)` with `status='registered'`
- **WHEN** the handler processes a `job.failed` delivery with payload `{external_ref:'ps:1:42:image:7', packshot_id:'packshot-uuid', job_id:'job-uuid', error_message:'upstream_validation_failed'}`
- **THEN** the `ps_qamera_packshot_link` row SHALL have `status='failed'`, `last_error_message='upstream_validation_failed'`
- **AND** the `ps_qamera_product_link` row's `status` SHALL remain `'registered'`
- **AND** the `ps_qamera_product_link` row's `last_synced_at` SHALL be bumped

#### Scenario: Oversized error_message is truncated to TEXT capacity
- **WHEN** the payload's `error_message` exceeds 65535 bytes
- **THEN** the persisted `last_error_message` SHALL be truncated to ≤65535 bytes

#### Scenario: Missing error_message persists NULL
- **WHEN** the payload omits `error_message`
- **THEN** the `last_error_message` column SHALL be persisted as `NULL`

### Requirement: job.cancelled upserts a cancelled packshot row

When the dispatcher routes a `job.cancelled` delivery, the JobCancelledHandler SHALL UPSERT a row in `ps_qamera_packshot_link` keyed on `qamera_packshot_id` with `status='cancelled'`, `qamera_job_id=<payload.job_id>` (NULL if omitted), `last_synced_at=NOW()`, `updated_at=NOW()`, `last_error_message=NULL`. The handler SHALL refresh `ps_qamera_product_link.last_synced_at` as in `job.completed`.

#### Scenario: Cancelled event overwrites a previously ready row
- **GIVEN** a `ps_qamera_packshot_link` row exists with `qamera_packshot_id='packshot-uuid'`, `status='ready'`
- **WHEN** the handler processes a `job.cancelled` delivery for the same `packshot_id`
- **THEN** the row's `status` SHALL be updated to `'cancelled'`
- **AND** `last_error_message` SHALL be cleared to NULL

#### Scenario: Cancelled event for unknown packshot creates a cancelled row
- **GIVEN** no row exists in `ps_qamera_packshot_link` for `qamera_packshot_id='packshot-uuid'`
- **WHEN** the handler processes a `job.cancelled` delivery with that `packshot_id`
- **THEN** a new row SHALL be inserted with `status='cancelled'`

### Requirement: job.retried refreshes timestamps but never changes status

When the dispatcher routes a `job.retried` delivery, the JobRetriedHandler SHALL refresh `ps_qamera_packshot_link.last_synced_at` and `updated_at` for the row matching `qamera_packshot_id` from the payload WITHOUT modifying `status` or `last_error_message`. If no row exists for the `packshot_id`, the handler SHALL be a no-op (the eventual terminal event — `job.completed`, `job.failed`, or `job.cancelled` — will create the row). The product heartbeat on `ps_qamera_product_link.last_synced_at` SHALL be refreshed in either case.

#### Scenario: Retried event bumps last_synced_at, leaves status alone
- **GIVEN** a `ps_qamera_packshot_link` row exists with `qamera_packshot_id='packshot-uuid'`, `status='pending'`, `last_synced_at='2026-05-27 10:00:00'`
- **WHEN** the handler processes a `job.retried` delivery for the same `packshot_id`
- **THEN** the row's `last_synced_at` SHALL be bumped to the dispatch instant
- **AND** `status` SHALL remain `'pending'`

#### Scenario: Retried for unknown packshot is a no-op for the packshot table
- **GIVEN** no row exists in `ps_qamera_packshot_link` for `qamera_packshot_id='packshot-uuid'`
- **WHEN** the handler processes a `job.retried` delivery with that `packshot_id`
- **THEN** no row SHALL be inserted in `ps_qamera_packshot_link`
- **AND** the `ps_qamera_product_link` heartbeat SHALL still be refreshed if the parsed `(shopId, productId)` matches an existing row

### Requirement: Packshot upsert is keyed on qamera_packshot_id

The module SHALL implement the packshot UPSERT via a single `INSERT … ON DUPLICATE KEY UPDATE` statement keyed on a `UNIQUE` index over `qamera_packshot_id`. The `ON DUPLICATE KEY UPDATE` clause SHALL refresh `status`, `qamera_job_id`, `last_synced_at`, `updated_at`, and `last_error_message`, and SHALL NOT modify `id_shop`, `id_product`, `qamera_packshot_ref`, or `created_at`.

#### Scenario: Concurrent upserts for the same packshot_id serialize on the index
- **WHEN** two webhook deliveries with the same `packshot_id` are processed concurrently
- **THEN** exactly one row SHALL exist for that `packshot_id` after both complete
- **AND** both dispatches SHALL terminate without raising

#### Scenario: Upsert never overwrites immutable columns
- **GIVEN** a row exists with `qamera_packshot_id='packshot-uuid'`, `id_shop=1`, `id_product=42`, `created_at='2026-05-27 10:00:00'`
- **WHEN** a `job.completed` upsert runs for the same `packshot_id` with a payload claiming `id_shop=99`
- **THEN** the row's `id_shop` SHALL remain `1`
- **AND** `created_at` SHALL remain unchanged

### Requirement: Dispatch never blocks or alters the HTTP ACK

The dispatcher SHALL run synchronously inside the webhook HTTP request lifecycle but SHALL NOT alter the response that the webhook-handler capability would otherwise produce. Whether the dispatch succeeds, partially writes, logs an error, or silently no-ops, the HTTP response SHALL remain `200 OK` with the JSON body `{"status":"ok"}` (or `{"status":"duplicate"}` on a duplicate `delivery_id`, per webhook-handler).

#### Scenario: DB error during dispatch still returns 200
- **WHEN** the dispatch raises `QameraDbException`
- **THEN** the HTTP response SHALL still be `200 OK` with body `{"status":"ok"}`

#### Scenario: Unknown event_type still returns 200
- **WHEN** the dispatch routes an unknown event_type and no handler runs
- **THEN** the HTTP response SHALL still be `200 OK` with body `{"status":"ok"}`
