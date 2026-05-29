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

### Requirement: Dispatch never blocks or alters the HTTP ACK

The dispatcher SHALL run synchronously inside the webhook HTTP request lifecycle but SHALL NOT alter the response that the webhook-handler capability would otherwise produce. Whether the dispatch succeeds, partially writes, logs an error, or silently no-ops, the HTTP response SHALL remain `200 OK` with the JSON body `{"status":"ok"}` (or `{"status":"duplicate"}` on a duplicate `delivery_id`, per webhook-handler).

#### Scenario: DB error during dispatch still returns 200
- **WHEN** the dispatch raises `QameraDbException`
- **THEN** the HTTP response SHALL still be `200 OK` with body `{"status":"ok"}`

#### Scenario: Unknown event_type still returns 200
- **WHEN** the dispatch routes an unknown event_type and no handler runs
- **THEN** the HTTP response SHALL still be `200 OK` with body `{"status":"ok"}`

### Requirement: product_ref parser accepts ps:shop:product and rejects everything else

The module SHALL parse the `payload.job.product_ref` field with format `ps:<shopId>:<productId>` into an immutable value containing `shopId` and `productId` as positive integers. Refs that do not match this exact shape SHALL cause the parser to throw an invalid-ref exception. The handler invoking the parser SHALL catch it, log at `warning` level, and skip the dispatch (no DB writes). This replaces the former image-suffixed `external_ref` parser — the webhook payload identifies the product by `job.product_ref` (shape `ps:shop:product`), never by an `:image:`-suffixed external_ref.

#### Scenario: Canonical product_ref parses to two positive integers
- **WHEN** the parser is given `'ps:1:42'`
- **THEN** it SHALL return a value with `shopId=1`, `productId=42`

#### Scenario: Image-suffixed ref is rejected
- **WHEN** the parser is given `'ps:1:42:image:7'`
- **THEN** it SHALL throw the invalid-ref exception (the webhook contract sends `ps:shop:product`, not the registration-time external_ref)

#### Scenario: Non-ps prefix is rejected
- **WHEN** the parser is given `'qamera:1:42'`
- **THEN** it SHALL throw the invalid-ref exception

#### Scenario: Non-numeric, negative, or whitespace-padded segments are rejected
- **WHEN** the parser is given `'ps:abc:42'`, `'ps:-1:42'`, `' ps:1:42'`, or `'ps:1:42 '`
- **THEN** it SHALL throw the invalid-ref exception

### Requirement: Job events refresh the product-link heartbeat via job.product_ref

For every routed `job.*` delivery (`completed`, `failed`, `cancelled`, `retried`), the handler SHALL parse `payload.job.product_ref` into `(shopId, productId)` and refresh the `ps_qamera_product_link` heartbeat for `(id_shop=shopId, id_product=productId)` by bumping `last_synced_at` and `updated_at` to `NOW()`. The heartbeat MUST NOT modify the Phase-3-owned columns `status`, `qamera_product_id`, or `last_error_message`. If no `ps_qamera_product_link` row matches, the handler SHALL log a `warning` and return without further writes. The per-job mirror update on `ps_qamera_packshot_job` (keyed on `payload.job.id`) is owned by the webhook-handler capability's "Job-event handlers update the local packshot_job table by qamera_job_id" requirement. No handler writes to `ps_qamera_packshot_link` — that table is removed.

#### Scenario: Heartbeat bumped for a known product
- **GIVEN** a `ps_qamera_product_link` row for `(id_shop=1, id_product=42)` with `status='registered'`, `qamera_product_id='abc'`, `last_synced_at='2026-05-27 10:00:00'`
- **WHEN** the handler processes a `job.completed` with `payload.job.product_ref='ps:1:42'`
- **THEN** the row's `last_synced_at` is bumped to the dispatch instant
- **AND** `status` stays `'registered'` and `qamera_product_id` stays `'abc'`

#### Scenario: Unknown product is logged and skipped
- **GIVEN** no `ps_qamera_product_link` row for `(id_shop=99, id_product=42)`
- **WHEN** the handler processes a delivery with `payload.job.product_ref='ps:99:42'`
- **THEN** no heartbeat write occurs and a `warning` is logged with `delivery_id` and `event_type`

#### Scenario: Malformed product_ref is logged and skipped
- **WHEN** `payload.job.product_ref` is absent or malformed
- **THEN** no DB writes occur and a `warning` is logged

