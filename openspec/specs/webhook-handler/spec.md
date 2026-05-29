# webhook-handler Specification

## Purpose

Defines how the module receives, authenticates, and persists inbound webhook deliveries from `qamera.ai`. This is the receive-and-verify layer only: HMAC-SHA256 signature verification (with multi-`v1=` tolerance for the upstream 48 h dual-sign rotation grace window), timestamp-based replay protection (±300 s past / +60 s future), delivery-id idempotency via the new `qamera_webhook_delivery` table primary key, an ACK contract that minimises upstream retries (200 on accept/duplicate, 400 on malformed input, 401 on missing signature, 405 on wrong method, 500 only on internal repository or server-config failure), forward-compatible event-type tolerance (unknown but well-formed types are recorded with `status='accepted'` for Phase 4.2 dispatch), and operator-visible logging through PrestaShop's `QameraAiModule` channel where rejection log lines carry structured reason codes but NEVER the secret, computed HMAC, or full raw body. Rejection paths NEVER persist a row (anti-DoS — the endpoint is unauthenticated and the table is otherwise an attacker fill target). Dispatching verified deliveries into product/image state is deliberately out of scope and is owned by Phase 4.2 (`add-webhook-event-dispatch`), which consumes the rows persisted here as its input queue.
## Requirements
### Requirement: Storefront webhook endpoint

The module SHALL expose a storefront HTTP endpoint that accepts inbound webhook deliveries from `qamera.ai`. The endpoint MUST be reachable without admin authentication, MUST be exempt from CSRF token validation, and MUST accept `POST` requests with a JSON body.

#### Scenario: GET request is rejected
- **WHEN** a client issues `GET /module/qameraai/webhook`
- **THEN** the module SHALL respond with HTTP `405 Method Not Allowed`

#### Scenario: POST without admin credentials is accepted for verification
- **WHEN** a client issues `POST /module/qameraai/webhook` without any PrestaShop admin session cookie
- **THEN** the module SHALL NOT short-circuit on admin auth and SHALL proceed to signature verification

#### Scenario: CSRF token is not required
- **WHEN** a client issues `POST /module/qameraai/webhook` without a PrestaShop CSRF token
- **THEN** the module SHALL NOT reject the request for CSRF reasons and SHALL proceed to signature verification

### Requirement: Raw request body is captured once before parsing

The module SHALL capture the raw HTTP request body as an immutable string before any JSON decoding, framework middleware, or PrestaShop helper accesses the input stream, and SHALL use that exact string both as input to signature verification and as the value persisted to `raw_payload`.

#### Scenario: Body is available for both verification and persistence
- **WHEN** a webhook delivery is received with a JSON body
- **THEN** the module SHALL pass the byte-for-byte raw body to the HMAC verifier
- **AND** the module SHALL persist the byte-for-byte raw body in `raw_payload`

#### Scenario: Empty body is rejected
- **WHEN** a `POST` arrives with an empty body
- **THEN** the module SHALL respond with HTTP `400 Bad Request` and SHALL NOT insert a row in `qamera_webhook_delivery`

### Requirement: Signature header parsing

The module SHALL parse the `X-Qamera-Signature` request header in the form `t=<unix-seconds>,v1=<hex>[,v1=<hex>…]` into a structured value containing the integer timestamp and the ordered list of `v1=` signature values. Parsing failures SHALL cause an HTTP `400` response without persistence.

#### Scenario: Header with single v1 value parses successfully
- **WHEN** the header equals `t=1716800000,v1=abcdef0123456789…`
- **THEN** parsing SHALL yield timestamp `1716800000` and one signature value `abcdef0123456789…`

#### Scenario: Header with two v1 values parses both
- **WHEN** the header equals `t=1716800000,v1=aaa…,v1=bbb…`
- **THEN** parsing SHALL yield timestamp `1716800000` and two signature values in header order

#### Scenario: Missing X-Qamera-Signature header
- **WHEN** the request has no `X-Qamera-Signature` header
- **THEN** the module SHALL respond with HTTP `401 Unauthorized`

#### Scenario: Missing t= field
- **WHEN** the header equals `v1=abc`
- **THEN** the module SHALL respond with HTTP `400 Bad Request`

#### Scenario: Non-numeric t= field
- **WHEN** the header equals `t=now,v1=abc`
- **THEN** the module SHALL respond with HTTP `400 Bad Request`

#### Scenario: No v1 values present
- **WHEN** the header equals `t=1716800000`
- **THEN** the module SHALL respond with HTTP `400 Bad Request`

#### Scenario: Malformed entry (e.g. duplicate t, trailing comma)
- **WHEN** the header equals `t=1716800000,t=1716800001,v1=abc` or `t=1716800000,v1=abc,`
- **THEN** the module SHALL respond with HTTP `400 Bad Request`

### Requirement: HMAC-SHA256 signature verification with multi-v1 tolerance

The module SHALL verify the signature by computing `hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret)` against the value of `QAMERAAI_WEBHOOK_SECRET` from the Back Office Configuration store, comparing against EACH `v1=` value in the parsed header using a constant-time comparison, and treating the delivery as authentic if ANY `v1=` value matches.

#### Scenario: Single v1 value matches local secret
- **WHEN** the header carries one `v1=` value that equals the locally-computed HMAC
- **THEN** verification SHALL succeed

#### Scenario: First v1 of two matches local secret
- **WHEN** the header carries two `v1=` values and the first equals the locally-computed HMAC
- **THEN** verification SHALL succeed

#### Scenario: Second v1 of two matches local secret
- **WHEN** the header carries two `v1=` values and only the second equals the locally-computed HMAC
- **THEN** verification SHALL succeed

#### Scenario: No v1 matches
- **WHEN** the header carries one or more `v1=` values and none equals the locally-computed HMAC
- **THEN** the module SHALL respond with HTTP `400 Bad Request` and SHALL NOT insert a delivery row

#### Scenario: Comparison is constant-time
- **WHEN** the module verifies a signature
- **THEN** the comparison SHALL be performed via `hash_equals()` and MUST NOT short-circuit on the first differing byte (`===`, `strcmp`, and `strncmp` are forbidden)

#### Scenario: Secret is read from Configuration, not source
- **WHEN** the module verifies a signature
- **THEN** the secret SHALL be read at request time from the `QAMERAAI_WEBHOOK_SECRET` Configuration value
- **AND** the secret value MUST NOT appear in any log line, error message, response body, or persisted row

### Requirement: Replay protection via timestamp window

The module SHALL reject deliveries whose signed timestamp lies outside the window `[now - 300s, now + 60s]` where `now` is the server's current Unix epoch second at the time of receipt. Rejected deliveries SHALL return HTTP `400 Bad Request` and SHALL NOT insert a row in `qamera_webhook_delivery`.

#### Scenario: Timestamp within window is accepted
- **WHEN** `now - t` is between 0 and 300 seconds inclusive (with valid signature)
- **THEN** verification SHALL pass the replay check

#### Scenario: Timestamp older than 300s is rejected
- **WHEN** `now - t > 300`
- **THEN** the module SHALL respond with HTTP `400 Bad Request`

#### Scenario: Timestamp more than 60s in the future is rejected
- **WHEN** `t - now > 60`
- **THEN** the module SHALL respond with HTTP `400 Bad Request`

#### Scenario: Replay check runs after signature verification
- **WHEN** a delivery has a valid signature but a stale timestamp
- **THEN** the module SHALL reject with HTTP `400` and SHALL log the rejection at level `error` with the parsed `t`, the server's `now`, and the delivery id

### Requirement: Delivery-id idempotency

The module SHALL deduplicate accepted deliveries by the value of the `X-Qamera-Request-Id` request header (the server's stable `webhook_deliveries` id, reused across retries), persisted as the primary key of the `qamera_webhook_delivery` table. There is NO body `delivery_id` field and NO `X-Qamera-Delivery-Id` header in the contract; the module SHALL NOT require or cross-check either.

#### Scenario: Missing X-Qamera-Request-Id header
- **WHEN** the request omits `X-Qamera-Request-Id`
- **THEN** the module SHALL respond with HTTP `400 Bad Request` and SHALL NOT insert a row

#### Scenario: First delivery is accepted and persisted
- **WHEN** an authentic delivery with `X-Qamera-Request-Id: D1` is received and no row with `delivery_id = D1` exists
- **THEN** the module SHALL insert a new row with `status = 'accepted'` and respond with HTTP `200 OK`

#### Scenario: Duplicate delivery is acknowledged without reprocessing
- **WHEN** an authentic delivery with `X-Qamera-Request-Id: D1` is received and a row with `delivery_id = D1` already exists
- **THEN** the module SHALL NOT insert a new row, SHALL NOT mutate the existing row, SHALL respond with HTTP `200 OK`, and SHALL log the duplicate at level `warning` with the id

#### Scenario: Retry of the same delivery reuses the id and dedups
- **WHEN** the server retries a previously-delivered row (same `webhook_deliveries.id` → same `X-Qamera-Request-Id`)
- **THEN** the module SHALL treat it as a duplicate of the original and respond `200 OK` without reprocessing

#### Scenario: Concurrent duplicates are serialised by the database
- **WHEN** two requests with the same `X-Qamera-Request-Id` are processed concurrently
- **THEN** exactly one row SHALL exist for that id after both complete, and both SHALL respond `200 OK`

### Requirement: Persisted delivery log

The module SHALL persist each accepted (and each duplicate-acknowledged) delivery to the `qamera_webhook_delivery` table created by the installer. Rejected deliveries (invalid signature, stale timestamp, malformed header or body, missing delivery id) SHALL NOT be persisted.

#### Scenario: Accepted delivery row contains the expected columns
- **WHEN** a delivery is accepted
- **THEN** the inserted row SHALL contain `delivery_id`, the server's current `received_at` UTC datetime, `event_type` from the body, `status = 'accepted'`, `last_error_message = NULL`, and `raw_payload` equal to the verified raw body

#### Scenario: Rejected deliveries are not persisted
- **WHEN** any rejection path is taken (signature, timestamp, malformed header, malformed body, missing delivery id, header/body delivery-id mismatch)
- **THEN** the module SHALL NOT insert or update any row in `qamera_webhook_delivery`

#### Scenario: Schema is created on module install
- **WHEN** the module is installed on a clean PrestaShop
- **THEN** `{prefix}qamera_webhook_delivery` SHALL exist with primary key `delivery_id`, columns as defined in design D8, and the secondary index `qamera_webhook_event_type` on `(event_type, received_at)`

#### Scenario: Schema is removed on module uninstall
- **WHEN** the module is uninstalled
- **THEN** `{prefix}qamera_webhook_delivery` SHALL be dropped

### Requirement: Event-type tolerance

The module SHALL read the event type from the body `event` field and accept any value whose shape matches `^[a-z][a-z0-9_.-]{0,63}$`. Unknown well-formed events SHALL be recorded with `status = 'accepted'` and SHALL NOT cause an error response — the recorded row is the dispatch substrate.

#### Scenario: Known events are accepted
- **WHEN** the body's `event` is one of `job.completed`, `job.failed`, `job.cancelled`, `job.retried`
- **THEN** the module SHALL persist the row with `status = 'accepted'`

#### Scenario: Unknown but well-formed event is accepted
- **WHEN** the body's `event` is `job.future_kind` and signature and timestamp are valid
- **THEN** the module SHALL persist the row with `status = 'accepted'` and `event_type = 'job.future_kind'` and respond `200 OK`

#### Scenario: Missing or malformed event is rejected
- **WHEN** the body's `event` is missing, empty, longer than 64 chars, or contains characters outside `[a-z0-9_.-]`
- **THEN** the module SHALL respond with HTTP `400 Bad Request`

### Requirement: ACK response contract

The module SHALL respond with HTTP status codes that minimise upstream retries while still surfacing genuine internal failures:
- `200 OK` for accepted deliveries and duplicate-acknowledged deliveries;
- `400 Bad Request` for malformed signature header, signature mismatch, replay window violation, malformed body, missing delivery id, header/body delivery-id mismatch, or malformed event type;
- `401 Unauthorized` if the `X-Qamera-Signature` header is missing entirely;
- `405 Method Not Allowed` for non-`POST` methods;
- `500 Internal Server Error` ONLY when the repository fails to persist an otherwise-valid delivery (the only case upstream should retry).

#### Scenario: Repository failure surfaces 500
- **WHEN** a signature-valid, fresh, non-duplicate delivery arrives and the database insert raises an exception (e.g. connection lost)
- **THEN** the module SHALL respond with HTTP `500 Internal Server Error`
- **AND** the module SHALL log the failure at level `error` with the delivery id and underlying exception class
- **AND** the response body MUST NOT include the database exception message, stack trace, or secret

#### Scenario: Response body is small and stable
- **WHEN** the module responds with `200`
- **THEN** the response SHALL be a JSON object of shape `{"status":"ok"}` or `{"status":"duplicate"}` with `Content-Type: application/json`

### Requirement: Operator-visible logging

The module SHALL emit log lines through `PrestaShopLogger` to the `QameraAiModule` channel for every webhook decision. Log lines SHALL NEVER include the `QAMERAAI_WEBHOOK_SECRET`, full request body, or computed HMAC bytes.

#### Scenario: Accepted delivery is logged at info
- **WHEN** a delivery is accepted
- **THEN** the module SHALL emit an `info` line containing the `delivery_id` (from `X-Qamera-Request-Id`) and the event

#### Scenario: Duplicate delivery is logged at warning
- **WHEN** a duplicate delivery is acknowledged
- **THEN** the module SHALL emit a `warning` line containing the id and the original `received_at`

#### Scenario: Rejection is logged at error with a structured reason code
- **WHEN** a delivery is rejected
- **THEN** the module SHALL emit an `error` line containing the rejection reason code (one of `missing_signature`, `malformed_signature`, `signature_mismatch`, `replay_window`, `missing_request_id`, `malformed_body`, `malformed_event_type`, `empty_body`, `method_not_allowed`) and, where available, the id

#### Scenario: Secrets and HMACs never appear in logs
- **WHEN** the module emits any log line
- **THEN** it MUST NOT contain the configured secret, any locally-computed HMAC hex, or the full raw body

### Requirement: Verified deliveries are dispatched after persistence

After a delivery is recorded with `status='accepted'`, the module SHALL invoke the event dispatcher exactly once with a `WebhookEvent` built from the body `event` (as `eventType`), the `X-Qamera-Request-Id` header (as `deliveryId`), `installationId=null`, and the entire decoded body (as `payload`). The HTTP response contract SHALL remain unchanged — the dispatch outcome SHALL NOT influence the status code or body. Any unhandled `\Throwable` from dispatch SHALL be caught at the controller layer, logged at `error` with the id and exception class, and the controller SHALL still return `200 OK` with `{"status":"ok"}`.

#### Scenario: Accepted delivery triggers exactly one dispatch
- **WHEN** a signature-valid, fresh, non-duplicate delivery is accepted and persisted
- **THEN** the dispatcher SHALL be invoked exactly once with a `WebhookEvent` carrying the id, the body `event`, and the whole decoded body as payload

#### Scenario: Duplicate delivery does NOT trigger dispatch
- **WHEN** a delivery with an id that already has a row is received
- **THEN** the dispatcher SHALL NOT be invoked and the response SHALL be `200 OK` `{"status":"duplicate"}`

#### Scenario: Rejected delivery does NOT trigger dispatch
- **WHEN** the delivery is rejected by any path (missing signature, malformed signature, signature mismatch, replay window, missing request id, malformed body, malformed event, empty body, method not allowed)
- **THEN** the dispatcher SHALL NOT be invoked

#### Scenario: Dispatcher exception is caught and the response stays 200
- **WHEN** a delivery is accepted and persisted but dispatch raises `\Throwable`
- **THEN** the controller SHALL catch it, log at `error` with the id and exception class, and respond `200 OK` `{"status":"ok"}`

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
- `last_error_message` set from `payload.job.error` (object → message string, truncated to TEXT capacity) on `job.failed`
- `last_synced_at` set to `gmdate('Y-m-d H:i:s')` on every successful handle

Unknown payload `job.status` values SHALL map to `pending` and log at WARNING, not throw; the handler MUST still ACK 200.

#### Scenario: job.completed updates an existing pending row to completed
- **GIVEN** a `ps_qamera_packshot_job` row with `qamera_job_id='j1'`, `status='pending'`
- **WHEN** a verified `job.completed` arrives with `payload.job.id='j1'` and `payload.outputs[0].url='https://…'`
- **THEN** the row's `status='completed'`, `output_url` is populated from `outputs[0].url`, `last_synced_at` is set, `last_error_message` stays NULL

#### Scenario: job.failed records error message from job.error and flips status
- **GIVEN** a row with `status='pending'`
- **WHEN** `job.failed` arrives with `payload.job.error.message='quota exceeded'`
- **THEN** the row's `status='failed'` and `last_error_message='quota exceeded'`

#### Scenario: Webhook arrives before submitter persisted (race condition)
- **GIVEN** no row exists for `qamera_job_id='j1'`
- **AND** the payload carries `payload.job.product_ref='ps:1:42'` and `payload.job.order_id='ord-1'`
- **WHEN** the handler runs
- **THEN** a new `ps_qamera_packshot_job` row is inserted with `qamera_job_id='j1'`, FK resolved by looking up `(id_shop=1, id_product=42)` in `ps_qamera_product_link`, status per the event
- **AND** the handler emits an INFO log noting `pre_submit_webhook_upsert`

#### Scenario: Unknown payload status is mapped to pending with a warning
- **GIVEN** a `job.*` payload carries `job.status='paused'`
- **THEN** the row's `status='pending'`, a WARNING records the unknown value, and the handler ACKs 200

### Requirement: Inbound webhook envelope matches the server wire contract

The module SHALL parse inbound deliveries against the actual server wire body and headers, NOT a wrapper envelope. The server posts (per `webhook-protocol.mdoc` / OpenAPI `WebhookPayload` / dispatcher `JSON.stringify(row.payload)`):

```json
{ "event": "job.completed",
  "delivered_at": "2026-05-09T08:00:00.000Z",
  "job": { "id":"…", "status":"completed", "job_type":"photo_shoot", "order_id":"…",
           "completed_at":"…", "error": null, "product_ref":"ps:1:42",
           "packshot_asset_id":"…", "product_label":"…", "voting":null, "voting_at":null },
  "outputs": [ { "url":"https://…", "type":"image/png", "width":1024, "height":1024 } ],
  "external_metadata": {…}, "callback_url":"…" }
```

with headers `X-Qamera-Signature: t=<unix>,v1=<hex>[,v1=<hex>]` and `X-Qamera-Request-Id: <delivery-uuid>`. There is NO top-level `delivery_id`, `event_type`, `installation_id`, or `payload` wrapper in the body. The `WebhookEvent` handed to the dispatcher SHALL carry: `eventType` = body `event`; `deliveryId` = `X-Qamera-Request-Id` header; `installationId` = null (absent from the contract); `payload` = the entire decoded body (so handlers read `payload['job']` and `payload['outputs']`).

#### Scenario: Real wire body decodes into a WebhookEvent
- **WHEN** a signature-valid `POST` arrives with the body above and `X-Qamera-Request-Id: D1`
- **THEN** the module SHALL build a `WebhookEvent` with `eventType='job.completed'`, `deliveryId='D1'`, `installationId=null`, and `payload` equal to the whole decoded object (including the nested `job` and `outputs`)

#### Scenario: Body with a legacy wrapper shape is not specially handled
- **WHEN** a body carries a top-level `event_type`/`delivery_id`/`payload` wrapper (the old invented shape)
- **THEN** the module SHALL read `event` (absent → 400 malformed event) and SHALL NOT treat the wrapper as authoritative — the wire contract is the nested `{event, job, outputs}` shape only

