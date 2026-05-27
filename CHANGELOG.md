# Changelog

All notable changes to the Qamera AI PrestaShop module are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Translations: [polski](CHANGELOG.pl.md) · [українська](CHANGELOG.uk.md)

## [1.3.0] — 2026-05-27

Phase 4.1 — inbound webhook receive-and-verify. The module now exposes a storefront endpoint that authenticates incoming deliveries from `qamera.ai` with HMAC-SHA256 (supporting the upstream 48 h dual-sign rotation grace window via multi-`v1=` headers), enforces a ±300 s past / 60 s future replay window, deduplicates on `X-Qamera-Delivery-Id` via a new `qamera_webhook_delivery` table, and persists every accepted delivery as the substrate for Phase 4.2 to dispatch against. PrestaShop 8.0–9.x, PHP 8.1+.

### Added

- **Storefront route** `POST /module/qameraai/webhook` (`controllers/front/webhook.php`, class `QameraaiWebhookModuleFrontController`). Unauthenticated by design — HMAC verification IS the authentication. CSRF-exempt. Reads raw input via `php://input` once, hands off to a framework-free orchestration core, emits JSON via `http_response_code()` + `echo` + `exit`. Bypasses Smarty and the PS template engine so the response body is byte-exact.
- **`QameraAi\Module\Webhook\WebhookRequestHandler`** — framework-free orchestration: method → secret-configured → signature header parse → delivery-id present → body size + JSON decode → body/header delivery-id match → event_type format → HMAC verify → replay window → repository persist.
- **`HmacVerifier`** computes `hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret)` and uses `hash_equals()` (no `===` / `strcmp` / `strncmp` against signature bytes). Iterates every `v1=` candidate without early break so timing depends only on candidate count.
- **`SignatureHeaderParser`** parses `t=<unix>,v1=<hex>[,v1=<hex>…]` into a typed `ParsedSignature` value object; raises `MalformedSignatureException` on every malformed-header sad path.
- **`ReplayGuard`** rejects deliveries whose signed timestamp lies outside `[now-300s, now+60s]`. Asymmetric tolerance is intentional (shared PS hosts skew "behind" more often than "ahead").
- **`WebhookDeliveryRepository`** persists accepted deliveries via a single `INSERT … ON DUPLICATE KEY UPDATE delivery_id=delivery_id`; the accepted-vs-duplicate outcome is read off `Db::Affected_Rows()` (1 = inserted, 0 = no-op-update branch). On the duplicate path one follow-up `SELECT` fetches the original `received_at` so the handler can include it in the warning log per spec.
- **New table `{prefix}qamera_webhook_delivery`** — PK on `delivery_id VARCHAR(64)`, secondary index on `(event_type, received_at)`. Wired through `Installer::createSchema()` and `upgrade/upgrade-1.3.0.php` (logs SQL errors at severity 3 if `CREATE TABLE` fails on older MariaDB row-format constraints).
- **`PrestaShopLoggerAdapter`** routes structured log lines (`info` / `warning` / `error`) to the existing `QameraAiModule` channel via the shared `PrestaShopLoggerWrapper`. Rejection reasons (`missing_signature`, `signature_mismatch`, `replay_window`, `body_too_large`, `secret_not_configured`, …) are surfaced as translatable XLIFF labels in en/pl/uk so Phase 4.2 BO can display them.

### Behaviour

- **ACK contract.** `200 {"status":"ok"}` for accepted, `200 {"status":"duplicate"}` for duplicate-acknowledged, `400` for malformed signature / replay window / body / event_type / delivery-id mismatch / body too large, `401` for missing signature, `405` for non-`POST`, `500` for repository failure OR a missing server-side secret. Duplicates and rejections both bypass dispatch — rejection paths NEVER persist a row (anti-DoS).
- **Multi-`v1=` rotation tolerance.** A delivery is authentic if ANY `v1=` value in the parsed header matches the locally-computed HMAC, supporting the upstream 48 h dual-sign rotation window without local "previous-secret" storage.
- **Body size cap.** `WebhookRequestHandler::MAX_BODY_BYTES = 65536`. Payloads larger than 64 KiB are rejected before `json_decode` to prevent PHP-FPM OOM-DoS via an unbounded body signed under a leaked secret.
- **Logs.** Accepted deliveries log at `info` with `delivery_id` and `event_type`; duplicates log at `warning` with the additional original `received_at`; rejections log at `error` with the structured reason code. Log lines never contain the secret value, the computed HMAC hex, or the full raw body (parameterised across all rejection paths in the unit suite).
- **Operator activation.** After installing the upgrade, the operator sets `callback_url` on the Qamera AI panel to `https://<shop>/module/qameraai/webhook` (or the legacy `index.php?fc=module&module=qameraai&controller=webhook`). Webhook secret is pasted into BO → Modules → Qamera AI → Configuration → Webhook secret.

### Deliberate non-goals (deferred to Phase 4.2 or later)

- **No dispatch.** Verified deliveries are persisted only; Phase 4.2 (`add-webhook-event-dispatch`) consumes the rows as its input queue.
- **No "previous secret" local store.** The upstream dual-sign window IS the rotation handoff; brief acknowledged downtime for old-secret deliveries after the operator pastes the new secret is intentional.
- **No BO replay UI.** Operators use the upstream `/installations/{id}/replay/{delivery_id}` endpoint.
- **No configurable HMAC algorithm.** SHA-256 is the only one in the upstream contract.
- **No `status='rejected'` persistence.** An unauthenticated endpoint that persisted every invalid request would be a fill-the-table DoS vector.

### Operator notes

- **Apache `setEnvIf`** may be required on stacks that strip custom request headers (`HTTP_X_QAMERA_SIGNATURE` / `HTTP_X_QAMERA_DELIVERY_ID`). README "Phase 4.1 — Webhook handler" lists the canonical snippet.
- **NTP.** A `replay_window` rejection spike usually means the server clock has drifted; `timedatectl status` on Linux hosts is the first place to look.

## [1.2.0] — 2026-05-26

Phase 3 first upstream sync: uploading an image for a product in the back office now registers that product upstream via the Qamera AI Plugin API. The Phase-2 `qamera_product_link` rows finally start filling their `qamera_product_id` column. PrestaShop 8.0–9.x, PHP 8.1+.

### Added

- **`actionWatermark` hook handler.** PS 8/9 fire `actionWatermark` after an image upload for a product (PS 9 dropped `actionProductImage`). The handler is the trigger for the upstream sync. Gated on the existing `QAMERAAI_AUTO_REGISTER_PRODUCTS` toggle; same swallow-throw + severity-2 log contract as the Phase-2 snapshot hooks — BO save action always succeeds regardless of upstream state.
- **`QameraAi\Module\Sync\ProductImageSyncService`** — orchestrates the full sync: load the bookkeeping row, resolve the primary image (cover preferred over the hook's hint image), request a presigned upload, PUT the image bytes, call `POST /images` with `product_metadata` (cascade-create path) or without (bare-image path on `registered` rows), and persist the result. In-memory dedup by `(id_product, id_image)` so PS's bulk-regenerate flows don't multi-fire upstream calls.
- **`QameraAi\Module\Sync\PrimaryImageResolver`** — cover → hook hint → first-by-position fallback chain. Returns the resolved `id_image` int (not a PS `Image` instance) so the rest of the pipeline stays array-shape-agnostic.
- **`QameraAi\Module\Sync\PresignedImageUploadStrategy`** — wraps `QameraApiClient::requestUpload` + a raw PUT on a dedicated Guzzle client (separate from the API client so PUT timeouts / headers differ from authed JSON traffic). Refreshes the presigned URL once if it has already expired (clock drift).
- **`QameraAi\Module\Api\Dto\ProductMetadata`** — value object for the upstream `product_metadata` payload. Enforces the upstream size limits (`display_name ≤ 500`, `sku ≤ 100`, `description ≤ 5000`) in the constructor so callers cannot build an invalid payload at runtime. Lives next to the other DTOs so a future `RegisterPackshotRequest` can reuse it.
- **`RegisterImageRequest` accepts `?ProductMetadata`.** New optional last-position constructor parameter; payload omits `product_metadata` entirely when null (key is absent, not `null`).
- **`ImageResponse.productId`.** New optional field surfacing the upstream-assigned product UUID returned on cascade-create responses.

### Behaviour

- **State transitions on `qamera_product_link.status`.** Phase 3 actually drives the state machine: `pending → registered` on successful cascade-create, `pending → error` on any failure in upload / PUT / register, `error → registered` on a subsequent retry that succeeds. On a `registered` row, subsequent images bump `last_synced_at` only — `qamera_product_id` is never overwritten.
- **Sanitized `last_error_message`.** Upstream exception types map to deterministic operator-facing messages: `Upstream validation: …`, `API credentials invalid (HTTP 401). Check API key in module configuration.`, `Rate limit exceeded — try again later. (HTTP 429)`, `Upstream server error (HTTP 5xx) after retries. Try again later.`, `Network error reaching Qamera AI: …`, and `Unexpected: <Class>: <message>` for everything else. Always truncated to 500 chars.
- **No-bookkeeping-row no-op.** If the operator turned the toggle on after creating a product, the next image upload finds no `qamera_product_link` row and logs an info-severity diagnostic without registering anything. The next `actionProductSave` creates the row and the following image will register normally.

### Changed

- **`QameraApiClient` is no longer `final`.** Dropped to let unit tests double the client. The client still has only one production caller path; nothing else relies on the class being closed.

### Known limitations

- Manual `error → pending` retry from the BO is not wired up yet — operators reload by uploading another image or wait for the Phase-4 product-tab UI.
- `registered → error` regression detection (a previously-successful row that should re-sync) is also Phase-4 territory — requires the cron reconciliation pass.
- Multistore replication: `actionWatermark` fires in the active shop context only, like Phase 2. Cross-shop fan-out is a follow-up.

## [1.1.0] — 2026-05-25

Phase 2 lazy bookkeeping: the module now records a local snapshot of every product the operator saves in the back office. No upstream Qamera AI API calls happen yet — those land in Phase 3 (image-sync). PrestaShop 8.0–9.x, PHP 8.1+.

### Added

- **`actionProductSave` hook handler.** Fires on both `Product::add()` and `Product::update()` in PS 8/9 — the primary entry point for capturing newly-created products. The legacy `actionProductAdd` hook is dispatched only by `ProductDuplicator` in PS 9, so registering Save was necessary to cover the BO "create product" flow.
- **`ps_qamera_product_link` snapshot columns.** Six new columns: `display_name_snapshot VARCHAR(500) NOT NULL`, `sku_snapshot VARCHAR(100) NULL`, `description_snapshot TEXT NULL`, `status ENUM('pending','registered','error') NOT NULL DEFAULT 'pending'`, `last_error_message TEXT NULL`, `last_synced_at DATETIME NULL`. The existing `qamera_product_id` column was relaxed from `NOT NULL` to `NULL` — it stays empty until Phase-3 upstream registration succeeds.
- **Idempotent schema migration.** `Installer::createSchema` introspects `INFORMATION_SCHEMA.COLUMNS` and only runs `ALTER` statements for columns that are missing or non-matching, so repeated installs / Phase-1 upgrades both no-op cleanly. A failed introspection probe now aborts the install rather than silently leaving a Phase-1 schema in place.
- **`QameraAi\Module\Sync\ProductSnapshotWriter`** — single `INSERT … ON DUPLICATE KEY UPDATE` keyed on `UNIQUE(id_product, id_shop)`. The UPDATE clause refreshes only snapshot columns and `updated_at`; `status`, `qamera_product_id`, `last_error_message`, `last_synced_at`, `qamera_product_ref`, and `created_at` are preserved across upserts so downstream sync state is never regressed.
- **`QameraAi\Module\Sync\ProductRefBuilder`** — deterministic `qamera_product_ref` formatted as `ps:{id_shop}:{id_product}`. Multistore safe (different shops yield distinct refs); rejects non-positive ids.

### Behaviour

- Hook bookkeeping is gated on the existing `QAMERAAI_AUTO_REGISTER_PRODUCTS` toggle (default off from Phase 1). Toggle OFF is a true no-op.
- All `\Throwable` from the writer is caught in the hook and logged via `PrestaShopLogger::addLog` at severity 2 with `object_type='QameraAiModule'`. BO "Save product" always succeeds from the operator's point of view, regardless of bookkeeping state.
- Snapshot reads use the shop's default language (`Configuration::get('PS_LANG_DEFAULT', null, null, $idShop)`); when that translation is missing, the writer falls back to the first non-empty language value and logs a warning.

### Changed

- **No upstream API impact.** The `QameraApiClient` surface, the upstream `/plugin/*` endpoints, and the webhook handler are untouched by this release.

### Known limitations

- New-product creation still requires the BO "Save" action; orphan rows from `Product::delete()` are not cleaned up (`actionProductDelete` lands in a follow-up change).
- `status='error'` rows refresh their snapshot on update but do not auto-retry — operator-driven retry lands with the Phase-4 product-tab UI.

## [1.0.0] — 2026-05-24

Inaugural release. Brings credential storage, an installable lifecycle, and a tested HTTP client to the Qamera AI Plugin API. PrestaShop 8.0–9.x, PHP 8.1+.

### Added

- **Back-office configuration page** under *Ulepszanie → Qamera AI*. Stores the API base URL, API key, webhook secret, an auto-register-new-products toggle, and a sync batch size. Secrets render masked; submitting the form without editing a masked field leaves the stored value untouched.
- **Module installer** — creates two MySQL tables (`qamera_product_link`, `qamera_packshot_link`), registers four PrestaShop hooks (`actionProductAdd`, `actionProductUpdate`, `displayAdminProductsExtra`, `displayBackOfficeHeader`) and seeds five configuration defaults. The uninstaller mirrors every step.
- **Typed HTTP client to the Qamera AI Plugin API.** One method per consumed endpoint (`me`, catalog reads, image and packshot register, presigned upload, job submission, product reads). Authentication, retry, idempotency-key generation on writes, and error-envelope decoding are baked in — callers never see a raw Guzzle exception.
- **Test connection** button on the configuration page. Posts to a CSRF-protected admin route, calls `GET /me` with the stored credentials, and renders the result inline (account name, credits balance, subscription plan, installation platform and status). The Save form is untouched by this action.
- **Polish and Ukrainian translations** for the back-office strings.
- **CI matrix** on PHP 8.1 / 8.2 / 8.3 (PHPCS PSR-12, PHPStan level 5 with PrestaShop core loaded via `_PS_ROOT_DIR_`, PHPUnit).

### Known limitations

- Hook handlers are no-op stubs; product synchronization, packshot submission flows, and webhook handling land in subsequent phases.
- Multistore is single-key (one API key per install). Per-shop credentials are a v2 follow-up.
- The configuration page edits secrets but cannot rotate the webhook HMAC — that lives in the Qamera AI panel.

[1.2.0]: https://github.com/200iqlabs/prestashop-qamera/releases/tag/v1.2.0
[1.1.0]: https://github.com/200iqlabs/prestashop-qamera/releases/tag/v1.1.0
[1.0.0]: https://github.com/200iqlabs/prestashop-qamera/releases/tag/v1.0.0
