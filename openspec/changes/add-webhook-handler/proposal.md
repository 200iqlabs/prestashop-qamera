## Why

Qamera AI packshot and scene jobs are asynchronous — `POST /plugin/packshots` returns a `job_id` in milliseconds but the actual render takes 30–90 seconds. Without an inbound webhook receiver the PrestaShop module never learns whether a job completed, failed, was cancelled, or was retried. This is the gating dependency for the Phase 4 packshot UI: nothing in the Back Office can show "Done", "Failed", or display generated assets until deliveries from `qamera.ai` can land, be authenticated, and be recorded.

This change introduces the receive-and-verify layer only. Translating verified deliveries into product/image bookkeeping changes is deliberately deferred to Phase 4.2 (`add-webhook-event-dispatch`) so the security-critical surface (HMAC verification, replay defence, idempotency) can ship and be hardened independently of business logic.

## What Changes

- **New storefront route** `POST /module/qameraai/webhook` (front controller, unauthenticated — HMAC is the authentication), exempt from PrestaShop CSRF and admin auth middleware.
- **HMAC-SHA256 verifier** that parses the `X-Qamera-Signature: t=<unix>,v1=<hex>[,v1=<hex>]` header, supports multiple `v1=` values to tolerate the upstream 48 h dual-sign rotation grace window, and uses `hash_equals()` for constant-time comparison.
- **Replay guard** that rejects deliveries where `now − t > 300 s` or `t > now + 60 s` (clock-ahead defence). Invalid timestamps return `400` and do NOT create an idempotency record.
- **Idempotency store**: new DB table `ps_qamera_webhook_delivery` keyed on `delivery_id` (from `X-Qamera-Delivery-Id` header). Duplicate delivery ids return `200 OK` immediately without re-processing.
- **Delivery logging**: every accepted delivery is persisted with `event_type`, `received_at`, `status`, `last_error_message`, and the raw payload, so operators have a tamper-evident audit trail and so Phase 4.2 has something to dispatch against.
- **Event-type tolerance**: handler accepts the four currently-defined event types (`job.completed`, `job.failed`, `job.cancelled`, `job.retried`) AND records-but-no-ops on unknown event types (forward-compatibility — upstream may add new events before the plugin is updated).
- **Operator-visible logging** to PrestaShop log channel `QameraAiModule` for both successful ACKs and verification failures (so the BO operator can diagnose signing-secret mismatches).
- **NOT in scope**: dispatching events to product/image state (Phase 4.2), webhook replay UI in BO, configurable HMAC algorithm, retaining a "previous secret" locally (operator pastes the new secret after rotation; brief acknowledged downtime during 48 h window for old-secret signed deliveries).

## Capabilities

### New Capabilities

- `webhook-handler`: inbound webhook receiver — storefront endpoint, HMAC-SHA256 signature verification with multi-`v1=` support, timestamp-based replay protection, delivery-id idempotency, persistent delivery log, ACK contract (200 on accept/duplicate, 400 on invalid signature/timestamp/body, 401 on missing signature, 500 only on internal repository failure).

### Modified Capabilities

<!-- None. `qamera-api-client` covers outbound calls only and is unaffected. The Phase 2 `prestashop-module-bootstrap` capability already mandates secrets-in-Configuration; we consume `QAMERAAI_WEBHOOK_SECRET` from that store without changing its contract. -->

## Impact

- **New code**: `src/Webhook/HmacVerifier.php`, `src/Webhook/ReplayGuard.php`, `src/Webhook/WebhookDeliveryRepository.php`, `src/Webhook/SignatureHeaderParser.php`, `src/Webhook/WebhookRequestHandler.php` (framework-free orchestration core), `src/Webhook/Log/PrestaShopLoggerAdapter.php`, and the legacy PrestaShop front controller at `controllers/front/webhook.php` (class `QameraaiWebhookModuleFrontController`). Design D1 / Q1 resolved this to the legacy `controllers/front/` path — PrestaShop 8/9 storefront controllers live there by convention and the Symfony route loader is admin-only in this project today.
- **New schema**: install/upgrade migration creating `{prefix}qamera_webhook_delivery` (PK `delivery_id` `VARCHAR(64)`, `received_at DATETIME`, `event_type VARCHAR(64)`, `status ENUM('accepted','duplicate','rejected')`, `last_error_message TEXT NULL`, `raw_payload MEDIUMTEXT`). Added via `src/Install/Installer.php` following the Phase 2 `qamera_product_link` pattern; uninstall drops the table. The upgrade-path script lives at `upgrade/upgrade-1.3.0.php` (module version bumped 1.2.0 → 1.3.0 in `composer.json` + `qameraai.php`).
- **Routing**: no `config/routes.yml` change — the legacy front controller is dispatched via `index.php?fc=module&module=qameraai&controller=webhook` (or the friendly URL `/module/qameraai/webhook` when URL rewriting is enabled) by PrestaShop's built-in module-front dispatcher.
- **Configuration**: consumes existing `QAMERAAI_WEBHOOK_SECRET` (introduced Phase 1). No new Configuration keys.
- **Dependencies**: no new Composer packages — relies on `hash_hmac`, `hash_equals`, and Symfony `Request::getContent()` (already pulled in by PrestaShop core).
- **Upstream contract**: read-only consumer of the contract documented in `apps/cg-worker/src/consumers/webhook-dispatcher/hmac.ts` upstream; this change does not request any upstream modification.
- **Operator action required after merge**: operator MUST set `callback_url` on the Qamera AI panel (`/home/pracownia-qamery-ai/settings/plugin-installations/<id>`) to `https://<shop>/module/qameraai/webhook` for deliveries to flow. Smoke is performed by triggering a packshot job in the panel and watching the `QameraAiModule` log channel.
- **Tests**: HMAC fixture matrix (valid single-v1, valid multi-v1 with first match, valid multi-v1 with second match, all-v1-fail, expired timestamp, future timestamp, malformed header, missing header), replay guard unit tests, repository tests against the PrestaShop `Db` mock, controller integration test with mocked verifier+guard+repo. CI on PHP 8.1/8.2/8.3 stays green; PHPStan level 5 and PHPCS PSR-12 clean.
