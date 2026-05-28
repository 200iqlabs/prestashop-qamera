# webhook-handler Specification (delta)

## ADDED Requirements

### Requirement: Verified deliveries are dispatched after persistence

After a delivery is recorded with `status='accepted'` in `qamera_webhook_delivery` (per the existing "Persisted delivery log" requirement), the module SHALL invoke the event dispatcher exactly once with a `WebhookEvent` value object built from the verified `event_type`, `delivery_id`, and parsed JSON payload. The HTTP response contract from the existing "ACK response contract" requirement SHALL remain unchanged — the dispatch outcome (success, error, no-op) SHALL NOT influence the response status code or body.

The dispatcher invocation SHALL be wrapped so that any unhandled exception (including `\Throwable`) is caught at the controller layer and converted to an `error`-level log line containing the `delivery_id` and exception class — the controller MUST still return `200 OK` with body `{"status":"ok"}`.

#### Scenario: Accepted delivery triggers exactly one dispatch
- **WHEN** a signature-valid, fresh, non-duplicate delivery is accepted and inserted into `qamera_webhook_delivery`
- **THEN** the dispatcher SHALL be invoked exactly once with a `WebhookEvent` carrying the persisted `delivery_id`, the `event_type` from the body, and the decoded JSON payload

#### Scenario: Duplicate delivery does NOT trigger dispatch
- **WHEN** an authentic delivery with id `D1` is received and a row with `delivery_id = D1` already exists
- **THEN** the dispatcher SHALL NOT be invoked
- **AND** the response SHALL remain `200 OK` with body `{"status":"duplicate"}` as specified by the existing "Duplicate delivery is acknowledged without reprocessing" scenario

#### Scenario: Rejected delivery does NOT trigger dispatch
- **WHEN** the delivery is rejected by any path defined in the existing rejection scenarios (missing signature, malformed signature, signature mismatch, replay window, missing/mismatched delivery_id, malformed body, malformed event type, empty body, method not allowed)
- **THEN** the dispatcher SHALL NOT be invoked

#### Scenario: Dispatcher exception is caught and the response stays 200
- **WHEN** a delivery is accepted and persisted, but the dispatcher invocation raises `\Throwable`
- **THEN** the controller SHALL catch the exception
- **AND** the controller SHALL emit a log line at `error` level containing the `delivery_id` and the exception's class name
- **AND** the response SHALL be `200 OK` with body `{"status":"ok"}`

#### Scenario: Unknown event_type still persists the row and still calls dispatch
- **WHEN** the body's `event_type` matches `^[a-z][a-z0-9_.-]{0,63}$` but is not one of the known types (e.g. `job.future_kind`)
- **THEN** the delivery row SHALL be persisted with `status='accepted'` (per the existing "Event-type tolerance" requirement)
- **AND** the dispatcher SHALL still be invoked
- **AND** the dispatcher SHALL emit an `info`-level log line for the unknown type and perform no DB writes (defined in the webhook-event-dispatch capability)
