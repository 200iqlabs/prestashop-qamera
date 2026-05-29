# Tasks — fix-webhook-payload-contract

## 0. Runtime confirmation (do first; de-risks the whole change)

- [ ] 0.1 Trigger one real delivery against the live container (submit a job, let it complete) and inspect BO Logs: confirm the inbound delivery is rejected `400` (reason `missing_delivery_id` / `delivery_id_mismatch` / `malformed_event_type`) or no-op'd. Capture the actual raw body + headers (`X-Qamera-Signature`, `X-Qamera-Request-Id`) for a regression fixture. If — unexpectedly — deliveries already succeed, STOP and re-scope: the change may be unnecessary.

## 1. Envelope parsing (`WebhookRequestHandler`)

- [ ] 1.1 Read the delivery id from the `X-Qamera-Request-Id` header; drop the `X-Qamera-Delivery-Id` lookup and the body `delivery_id` cross-check. Missing `X-Qamera-Request-Id` → 400 with reason `missing_request_id`.
- [ ] 1.2 Read the event type from the body `event` field (keep the `^[a-z][a-z0-9_.-]{0,63}$` shape gate).
- [ ] 1.3 Build `WebhookEvent` with `eventType=<body.event>`, `deliveryId=<X-Qamera-Request-Id>`, `installationId=null`, `payload=<entire decoded body>`.
- [ ] 1.4 Update `RejectionReason` codes: replace `MISSING_DELIVERY_ID`/`DELIVERY_ID_MISMATCH` with `MISSING_REQUEST_ID`. Keep the rest.
- [ ] 1.5 Confirm HMAC (`t.rawBody`), replay window, and `qamera_webhook_delivery` dedup are unchanged (only the id source differs). No schema change to the delivery table.

## 2. PayloadExtractor — nested access

- [ ] 2.1 Add nested-path helpers to `PayloadExtractor` (e.g. `jobString($payload, 'product_ref')`, `jobObject()`, `firstOutputUrl()`), so the four handlers read `payload['job']['…']` and `payload['outputs'][0]['url']` without scattered `isset()` chains. Keep the http(s)-URL sanitisation for output URLs.

## 3. product_ref parser (replaces ExternalRefParser)

- [ ] 3.1 Add a parser for `^ps:([1-9][0-9]*):([1-9][0-9]*)$` → `(shopId, productId)`, throwing on any other shape (incl. the image-suffixed form).
- [ ] 3.2 Remove `ExternalRefParser` (image-suffixed) and `ExternalRef`/`InvalidExternalRefException` if unused elsewhere (grep first); otherwise repurpose the file. The webhook path no longer parses `external_ref`.

## 4. Handlers — read real fields, drop packshot_link

- [ ] 4.1 `JobCompletedHandler`, `JobFailedHandler`, `JobCancelledHandler`, `JobRetriedHandler`: parse `payload.job.product_ref` → heartbeat `ps_qamera_product_link`; upsert `ps_qamera_packshot_job` keyed on `payload.job.id`. Remove all `PackshotLinkUpdater` usage and `external_ref`/`packshot_id` reads.
- [ ] 4.2 `JobCompletedHandler`: `output_url` from `payload.outputs[0].url` (+ expiry when the output carries one).
- [ ] 4.3 `JobFailedHandler`: `last_error_message` from `payload.job.error` (object → message string), truncated to 65535 bytes.
- [ ] 4.4 Keep the event→status map (`completed`/`failed`/`cancelled`/`in_progress`); unknown `job.status` → `pending` + WARNING, still ACK 200.

## 5. PackshotJobUpdater — FK + order source

- [ ] 5.1 Resolve the pre-submit-race FK via the parsed `(shopId, productId)` from `job.product_ref` (not the old `packshot_external_ref`); take `order_id` from `payload.job.order_id`. Drop the `payloadExternalRef` parameter.

## 6. Drop ps_qamera_packshot_link (full cleanup)

- [ ] 6.1 Delete `src/Webhook/Event/PackshotLinkUpdater.php`.
- [ ] 6.2 Remove the table from `Installer::createTables()` and delete `migratePackshotLinkSchema()`; keep `dropSchema()` dropping it (uninstall stays clean during transition).
- [ ] 6.3 Add `upgrade/upgrade-1.6.0.php` (`upgrade_module_1_6_0`): `DROP TABLE IF EXISTS {prefix}qamera_packshot_link;` (INFORMATION_SCHEMA-guarded log-on-failure pattern).
- [ ] 6.4 Remove the `PackshotLinkUpdater` wiring from `controllers/front/webhook.php` `buildDispatcher()` and the handlers' constructors.
- [ ] 6.5 Bump module version 1.5.0 → 1.6.0 (`config.xml`, `config_pl.xml`, `qameraai.php`).

## 7. Tests

- [ ] 7.1 Rebuild `tests/Unit/Webhook/*` fixtures from the REAL wire body (`{event, delivered_at, job:{…}, outputs:[…]}`) and headers (`X-Qamera-Signature`, `X-Qamera-Request-Id`). Add a fixture derived from `webhook-protocol.mdoc` as a contract anchor.
- [ ] 7.2 `WebhookRequestHandler` tests: id from `X-Qamera-Request-Id`; event from body `event`; dedup on the request id; missing request id → 400; old wrapper shape no longer accepted.
- [ ] 7.3 Handler tests: `job.product_ref` parsed; `outputs[0].url` → `output_url`; `job.error.message` → `last_error_message`; mirror keyed on `job.id`; no `ps_qamera_packshot_link` writes.
- [ ] 7.4 Remove/replace tests asserting `external_ref`/`packshot_id`/`PackshotLinkUpdater` behaviour.
- [ ] 7.5 `SubmitWebhookEndToEndTest` updated to the real payload shape.

## 8. Static analysis + lint

- [ ] 8.1 `vendor/bin/phpcs` clean (PSR-12).
- [ ] 8.2 `vendor/bin/phpstan analyse` clean at level 5 across 8.1/8.2/8.3.
- [ ] 8.3 `vendor/bin/phpunit` green.

## 9. Smoke (operator-driven, main checkout on this branch)

- [ ] 9.1 Install/upgrade on the live container; confirm `ps_qamera_packshot_link` is dropped and the module still installs/uninstalls cleanly.
- [ ] 9.2 Trigger a job to completion; confirm the inbound `job.completed` is now accepted `200` and updates the matching `ps_qamera_packshot_job` row (status `completed`, `output_url` populated from `outputs[0].url`).
- [ ] 9.3 Trigger a failing job; confirm `job.failed` lands `status='failed'` + `last_error_message` from `job.error`.
- [ ] 9.4 Confirm a retried delivery (same `X-Qamera-Request-Id`) is deduped (`{"status":"duplicate"}`).
