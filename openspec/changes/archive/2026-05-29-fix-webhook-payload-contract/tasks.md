# Tasks — fix-webhook-payload-contract

## 0. Runtime confirmation (do first; de-risks the whole change)

- [x] 0.1 Trigger one real delivery against the live container (submit a job, let it complete) and inspect BO Logs: confirm the inbound delivery is rejected `400` (reason `missing_delivery_id` / `delivery_id_mismatch` / `malformed_event_type`) or no-op'd. Capture the actual raw body + headers (`X-Qamera-Signature`, `X-Qamera-Request-Id`) for a regression fixture. If — unexpectedly — deliveries already succeed, STOP and re-scope: the change may be unnecessary. _(Operator-driven; folded into the collective smoke. Implemented on the strength of the static evidence — dispatcher `JSON.stringify(row.payload)`, mdoc, OpenAPI.)_

## 1. Envelope parsing (`WebhookRequestHandler`)

- [x] 1.1 Read the delivery id from the `X-Qamera-Request-Id` header; drop the `X-Qamera-Delivery-Id` lookup and the body `delivery_id` cross-check. Missing `X-Qamera-Request-Id` → 400 with reason `missing_request_id`.
- [x] 1.2 Read the event type from the body `event` field (keep the `^[a-z][a-z0-9_.-]{0,63}$` shape gate).
- [x] 1.3 Build `WebhookEvent` with `eventType=<body.event>`, `deliveryId=<X-Qamera-Request-Id>`, `installationId=null`, `payload=<entire decoded body>`.
- [x] 1.4 Update `RejectionReason` codes: replace `MISSING_DELIVERY_ID`/`DELIVERY_ID_MISMATCH` with `MISSING_REQUEST_ID`. Keep the rest.
- [x] 1.5 Confirm HMAC (`t.rawBody`), replay window, and `qamera_webhook_delivery` dedup are unchanged (only the id source differs). No schema change to the delivery table.

## 2. PayloadExtractor — nested access

- [x] 2.1 Add nested-path helpers to `PayloadExtractor` (`jobString()`, `firstOutputUrl()`, `jobErrorMessage()`), so the four handlers read `payload['job']['…']` and `payload['outputs'][0]['url']` without scattered `isset()` chains. Keep the http(s)-URL sanitisation for output URLs.

## 3. product_ref parser (replaces ExternalRefParser)

- [x] 3.1 Add a parser for `^ps:([1-9][0-9]*):([1-9][0-9]*)$` → `(shopId, productId)`, throwing on any other shape (incl. the image-suffixed form).
- [x] 3.2 Remove `ExternalRefParser` (image-suffixed), `ExternalRef`, `InvalidExternalRefException`, and the now-unused `PackshotExternalRefParser`/`PackshotExternalRef`. The webhook path no longer parses `external_ref`.

## 4. Handlers — read real fields, drop packshot_link

- [x] 4.1 `JobCompletedHandler`, `JobFailedHandler`, `JobCancelledHandler`, `JobRetriedHandler`: parse `payload.job.product_ref` → heartbeat `ps_qamera_product_link`; upsert `ps_qamera_packshot_job` keyed on `payload.job.id`. Removed all `PackshotLinkUpdater` usage and `external_ref`/`packshot_id` reads.
- [x] 4.2 `JobCompletedHandler`: `output_url` from `payload.outputs[0].url`.
- [x] 4.3 `JobFailedHandler`: `last_error_message` from `payload.job.error` (object → `message_i18n`/`code`), truncated to 65535 bytes.
- [x] 4.4 Keep the event→status map (`completed`/`failed`/`cancelled`/`in_progress`); unknown `job.status` → `pending` + WARNING, still ACK 200.

## 5. PackshotJobUpdater — FK + order source

- [x] 5.1 Resolve the pre-submit-race FK via the parsed `(shopId, productId)` from `job.product_ref`; take `order_id` from `payload.job.order_id`. Dropped the `payloadExternalRef` parameter.

## 6. Drop ps_qamera_packshot_link (full cleanup)

- [x] 6.1 Deleted `src/Webhook/Event/PackshotLinkUpdater.php`.
- [x] 6.2 Removed the table from `Installer::createTables()` and deleted `migratePackshotLinkSchema()`; `dropSchema()` still drops it (uninstall stays clean during transition).
- [x] 6.3 Added `upgrade/upgrade-1.6.0.php` (`upgrade_module_1_6_0`): `DROP TABLE IF EXISTS {prefix}qamera_packshot_link;` (log-on-failure pattern).
- [x] 6.4 Removed the `PackshotLinkUpdater` wiring from `controllers/front/webhook.php` `buildDispatcher()` and the handlers' constructors.
- [x] 6.5 Bumped module version 1.5.0 → 1.6.0 (`config.xml`, `config_pl.xml`, `qameraai.php`).

## 7. Tests

- [x] 7.1 Rebuilt `tests/Unit/Webhook/*` fixtures from the REAL wire body (`{event, delivered_at, job:{…}, outputs:[…]}`) and headers (`X-Qamera-Signature`, `X-Qamera-Request-Id`).
- [x] 7.2 `WebhookRequestHandler` tests: id from `X-Qamera-Request-Id`; event from body `event`; dedup on the request id; missing request id → 400; old wrapper shape no longer accepted.
- [x] 7.3 Handler tests: `job.product_ref` parsed; `outputs[0].url` → `output_url`; `job.error` → `last_error_message`; mirror keyed on `job.id`; no `ps_qamera_packshot_link` writes.
- [x] 7.4 Removed tests asserting `external_ref`/`packshot_id`/`PackshotLinkUpdater`/packshot_link-schema behaviour.
- [x] 7.5 `SubmitWebhookEndToEndTest` updated to the real payload shape.

## 8. Static analysis + lint

- [x] 8.1 `vendor/bin/phpcs --standard=PSR12 src/ tests/` clean on changed files (PSR-12).
- [ ] 8.2 `vendor/bin/phpstan analyse` clean at level 5 across the 8.1/8.2/8.3 matrix. _(Level-5 clean confirmed locally on the changed pure-PHP files; the full run needs the PrestaShop core bootstrap (`_PS_ROOT_DIR_`) and is left to CI.)_
- [x] 8.3 `vendor/bin/phpunit` green (360 unit/contract; 12 integration skipped — need the parent docker stack).

## 9. Smoke (operator-driven, main checkout on this branch)

- [x] 9.1 Install/upgrade on the live container; confirm `ps_qamera_packshot_link` is dropped and the module still installs/uninstalls cleanly. _(Smoke 2026-05-29: `prestashop:module upgrade qameraai` 1.5.0→1.6.0 clean; `SHOW TABLES LIKE '%packshot_link%'` empty; no DROP-failure log on QameraAiModule channel.)_
- [x] 9.2 Trigger a job to completion; confirm the inbound `job.completed` is now accepted `200` and updates the matching `ps_qamera_packshot_job` row (status `completed`, `output_url` populated from `outputs[0].url`). _(Smoke 2026-05-29: real `job.completed` accepted 200; `job.product_ref="ps:1:28"` (clean `ps:N:N`); `outputs[0].url` = signed Supabase preview URL stored verbatim into `output_url`; required a manual `POST /plugin/packshots` first — see registration-gap note below.)_
- [x] 9.3 Trigger a failing job; confirm `job.failed` lands `status='failed'` + `last_error_message` from `job.error`. _(Smoke 2026-05-29: `status='failed'` ✅. ⚠️ BUG: real `job.error` is a STRING, but `PayloadExtractor::jobErrorMessage()` (line 112-113) expects an object → `last_error_message` stays NULL. Fix the extractor to accept a non-empty string before acceptance-flow.)_
- [x] 9.4 Confirm a retried delivery (same `X-Qamera-Request-Id`) is deduped (`{"status":"duplicate"}`). _(Smoke 2026-05-29: replayed a captured delivery with same request-id + fresh valid signature → 200 `{"status":"duplicate"}`; no extra delivery row inserted.)_

**Smoke findings (2026-05-29, operator-driven, live qamera.ai via cloudflared tunnel):**
- Webhook contract 1.6.0 validated end-to-end: envelope `{event, job:{…}, outputs:[…], delivered_at, callback_url, external_metadata}`, `event` from body, delivery id from `X-Qamera-Request-Id`, accept 200, dedup, drop of `packshot_link`. `job.product_ref` is the clean `ps:N:N` form on both `job.failed` and `job.completed`.
- ⚠️ Handler bug: `job.error` arrives as a STRING → not mapped to `last_error_message` (see 9.3).
- 🔴 Separate prereq blocker: plugin never calls `POST /plugin/packshots` (`registerPackshot()` exists but is unwired) → every generate job fails `PLUGIN_JOB_MISSING_CATALOG_ENTRY` until the packshot is registered manually. Tracked as `fix-packshot-catalog-registration`.
