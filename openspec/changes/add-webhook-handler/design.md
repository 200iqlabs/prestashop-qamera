## Context

PrestaShop module Phase 4 introduces async packshot rendering. `POST /plugin/packshots` returns immediately with a `job_id`; the actual work happens on `qamera.ai` workers and takes 30–90 s. Without an inbound receiver, the module has no way to learn the outcome.

Upstream (`saas-platform`) has been delivering webhooks to other channels for months; its contract is stable and documented in `apps/cg-worker/src/consumers/webhook-dispatcher/hmac.ts`:

- Transport: `POST` JSON to the installation's stored `callback_url`.
- Auth: header `X-Qamera-Signature: t=<unix-seconds>,v1=<hex-sha256>[,v1=<hex-sha256>]`; signed string is `<timestamp>.<raw-body>` (literal dot, no whitespace); HMAC-SHA256, hex-encoded lowercase.
- Idempotency: header `X-Qamera-Delivery-Id: <uuid>` is canonical and duplicated in the body.
- Rotation: during 48 h after secret rotation upstream signs with BOTH current and previous secrets, producing a header with two `v1=` values. The plugin only stores the current secret; it must accept the delivery if any `v1=` verifies against the local `QAMERAAI_WEBHOOK_SECRET`.
- Event types in play today: `job.completed`, `job.failed`, `job.cancelled`, `job.retried` (the last one was added 2026-05-22 and is part of the stable surface).

Local constraints (CLAUDE.md): PHP 8.1+ strict types, PSR-12, PHPStan level 5, PrestaShop 8.0+/9.x compat, secrets only in Back Office Configuration, no live `qamera.ai` calls in CI, TDD via `superpowers:test-driven-development`. The Phase 1 `prestashop-module-bootstrap` spec defines masked-secret render and skip-persist-on-mask submission for `QAMERAAI_WEBHOOK_SECRET`; this change consumes that store read-only.

Existing migration pattern is in `src/Install/Installer.php` (creates `qamera_product_link`, `qamera_packshot_link`); we'll add `qamera_webhook_delivery` alongside them. Routes live in `config/routes.yml`; today they're all `Admin\…` (BO). This change introduces the first storefront entry point, which in PrestaShop means a `ModuleFrontController` subclass — front controllers are dispatched by the legacy `index.php?fc=module&module=qameraai&controller=webhook` router, NOT by Symfony routes. The route is reachable both via the legacy URL and the friendly URL `/module/qameraai/webhook` when URL rewriting is enabled.

Stakeholders: operator Paweł (Back Office user observing deliveries via `QameraAiModule` log channel), upstream `saas-platform` (no contract change requested), Phase 4.2 (`add-webhook-event-dispatch`) which will consume the persisted delivery rows as its input queue.

## Goals / Non-Goals

**Goals:**

- Authenticate inbound deliveries with HMAC-SHA256, constant-time comparison, multi-`v1=` tolerance for the 48 h upstream rotation grace window.
- Reject replays and clock-skewed deliveries before any persistence (300 s past tolerance, 60 s future tolerance).
- Deduplicate at the `delivery_id` boundary, persistently, across PHP-FPM worker restarts and across multi-server installs (DB row uniqueness, not in-memory cache).
- Record every accepted delivery (event type, raw payload, status) so Phase 4.2 has a durable, replayable substrate and so operators can audit.
- ACK semantics that minimise upstream retries: `200` on accept and on duplicate, `400` on signature/timestamp/body errors, `401` on missing signature header, `500` strictly on internal repository failure (the only case upstream should retry on).
- Stay forward-compatible with new event types — unknown types are recorded with `status='accepted'` and a no-op outcome rather than rejected.

**Non-Goals:**

- Translating `job.completed` into product/image state — Phase 4.2.
- A BO UI for browsing deliveries or replaying — operator uses the upstream `/installations/{id}/replay/{delivery_id}` endpoint.
- Keeping a "previous secret" locally; the upstream-side dual-sign window IS the rotation handoff, and brief acknowledged downtime for old-secret deliveries after the operator pastes the new local secret is intentional.
- Configurable HMAC algorithm (only SHA-256 is in the upstream contract today).
- Front-office surfacing of any kind.

## Decisions

**D1: Front controller via PrestaShop legacy dispatcher, not Symfony route.**
Rationale: storefront entry points in PS 8/9 are conventionally `ModuleFrontController` subclasses under `controllers/front/` (legacy folder). Symfony routes in `config/routes.yml` are admin-only in this project today, and adding a storefront Symfony route would require additional bootstrap (the route loader is wired for `_legacy_controller` admin contexts). The legacy front-controller path is reachable at `/module/qameraai/webhook` with URL rewriting enabled, and at `index.php?fc=module&module=qameraai&controller=webhook` without. Alternative considered: storefront Symfony route — rejected to avoid expanding the route-loader scope in a security-sensitive change. Source location: `controllers/front/webhook.php` (PS convention) registering class `QameraaiWebhookModuleFrontController`, which delegates immediately to `QameraAi\Module\Webhook\WebhookRequestHandler` so all real logic lives in PSR-4 `src/Webhook/` and is unit-testable without the PS runtime.

**D2: HMAC verifier as a stateless service operating on `(rawBody, signatureHeader, secret, nowEpoch)`.**
No singletons, no static state, no clock injection via globals — the controller passes the current epoch from an injected `Clock` interface so tests are deterministic. Rationale: PHPStan level 5 + TDD-first.

**D3: Signature header parser is split from the verifier.**
The parser turns `t=1716800000,v1=abc,v1=def` into `(timestamp: int, signatures: string[])` and is responsible for the malformed-header `400` path. The verifier just iterates signatures and calls `hash_equals`. Rationale: parsing has many sad paths (missing `t=`, non-numeric `t`, missing `v1=`, duplicate `t=`, trailing comma, whitespace inside values) and isolating them keeps the verifier readable and 100 %-branch testable.

**D4: Replay guard window is 300 s past / 60 s future, hardcoded.**
Mirrors the upstream `MAX_AGE_SECONDS` used by the dispatcher and Stripe's well-known constants. Asymmetric because real-world clock skew on PS hosts (especially shared hosting) is overwhelmingly "host clock behind", so we tolerate more lateness than future-skew, and the 60 s future is purely a defence against deliberately crafted future-timestamp replays. Not configurable in v1 — surfacing it in the Configuration page would be premature and introduces a foot-gun.

**D5: Idempotency keyed on `X-Qamera-Delivery-Id` header (not body field).**
The header is the canonical id per upstream contract. The body is checked for shape but not used for dedup, since a malicious sender that controls the body but not the header cannot forge duplicates anyway. If the body's `delivery_id` differs from the header, treat as a malformed delivery (`400`). Rationale: defence-in-depth, single source of truth.

**D6: Dedup via DB unique index, not application-level lock.**
The `qamera_webhook_delivery` table has `delivery_id` as PRIMARY KEY. Insert path uses `INSERT … ON DUPLICATE KEY UPDATE delivery_id=delivery_id` (no-op on dup) and reads the row back; if `received_at` is older than the request just received, this is a duplicate and the controller returns `200` without dispatch. Rationale: PHP-FPM workers and multi-server installs cannot share an in-memory mutex; the database is the only correct serialisation point. MySQL's PK is already serialisable on insert.

**D7: Raw body captured via Symfony `Request::getContent()`, but the front controller path bypasses Symfony entirely.**
Since D1 puts the entry point on the legacy `ModuleFrontController` path, the raw body comes from `file_get_contents('php://input')` inside the front controller and is then handed to `WebhookRequestHandler`. The handler accepts a raw string body, the signature header, and the delivery-id header — it does NOT depend on Symfony or PrestaShop at all. Rationale: testability and clarity about who consumes the input stream (PHP input streams are read-once; reading twice yields an empty string, which has burned other PS modules historically).

**D8: Persisted columns.**
`{prefix}qamera_webhook_delivery`:
- `delivery_id VARCHAR(64) NOT NULL` (PK; UUID v4 today but format-agnostic in case upstream switches),
- `received_at DATETIME NOT NULL`,
- `event_type VARCHAR(64) NOT NULL`,
- `status ENUM('accepted','duplicate','rejected') NOT NULL`,
- `last_error_message TEXT NULL`,
- `raw_payload MEDIUMTEXT NOT NULL`,
- KEY `qamera_webhook_event_type` (`event_type`, `received_at`) for Phase 4.2 to scan unprocessed-by-type efficiently.

`raw_payload` is the verified raw body — keeping it lets Phase 4.2 dispatch without re-fetching anything, and lets the operator export a delivery for upstream support if needed. `MEDIUMTEXT` accommodates payloads with embedded base64'd error contexts; typical packshot payloads are <2 KiB but the upstream contract does not cap them.

**D9: `status='rejected'` rows are NOT persisted in v1.**
Originally considered storing every rejection for forensics. Rejected: an unauthenticated endpoint is a DoS vector — persisting every invalid request lets an attacker fill the table at line rate. Verified deliveries only. The enum still includes `'rejected'` for forward-compatibility with a future rate-limited rejection log.

**D10: Operator-visible logging via PrestaShop `PrestaShopLogger` channel `QameraAiModule`.**
Same channel used elsewhere in the module. `info` for accepts, `warning` for duplicates, `error` for verification failures and repository failures. Log line includes `delivery_id`, `event_type`, decision, and (for rejections) the parser/verifier/guard error code — never the secret, never the full body.

**D11: Tests use raw HMAC math, no upstream stub.**
Test fixtures construct request bodies, sign them with `hash_hmac('sha256', "{$ts}.{$body}", $secret)`, and feed the handler. The dual-sign rotation case is exercised by signing with one secret while configuring the handler with a different one and asserting reject, plus a multi-`v1=` case where the second `v1=` matches. Rationale: zero CI dependency on upstream availability and the upstream HMAC contract is small enough to reproduce by hand.

## Risks / Trade-offs

- **[Risk] Reading `php://input` twice yields empty string.** → Mitigation: capture into a single `string` variable in the front controller before any framework code touches it; verifier and repository operate on the string, not the stream. Test covers double-read by calling the handler twice on the same `Request` shape.
- **[Risk] PrestaShop's `Tools::strtolower` / `Tools::getValue` may URL-decode or normalise headers.** → Mitigation: read headers via `apache_request_headers()` with `getallheaders()` fallback, but accept that some hosts proxy headers under `HTTP_X_QAMERA_SIGNATURE` in `$_SERVER`. The controller checks both. Test covers both shapes.
- **[Risk] Clock skew on shared PS hosts is real and can cause widespread false rejects.** → Mitigation: 300 s past tolerance is generous; operator logs include the parsed `t` and `now` so skew is diagnosable in 30 s. Doc note in `README.md` Phase 4 section directs operators to check NTP if rejections spike.
- **[Risk] An attacker spams the endpoint forcing the verifier to run HMAC repeatedly.** → Mitigation: SHA-256 of small bodies on PHP is cheap (~µs); no rate limit in v1. If upstream throughput grows, add `fail2ban`-style guidance in `README.md` — not in module code (PS hosts vary too widely to ship a one-size-fits-all limiter).
- **[Risk] `MEDIUMTEXT` payloads pile up over months.** → Mitigation: not v1's problem. Phase 4.2 will introduce a retention policy (e.g. prune after 30 days post-`processed_at`) once we know real volumes.
- **[Trade-off] Returning `500` on repository failure causes upstream to retry indefinitely.** → Accepted: upstream's retry queue has exponential backoff and a TTL, and a hard storage failure on the plugin side should NOT be silently swallowed — operator MUST be paged via logs. If this becomes a real-world problem we'll surface a `200` + `status='internal_error'` row in Phase 4.2.
- **[Trade-off] No "previous secret" local store.** → Accepted as a deliberate operator UX call (CLAUDE.md "out of scope" / upstream OQ marker). Brief downtime during rotation is acceptable for a self-hosted module.

## Migration Plan

1. **Install path**: `Installer::install()` adds a new step `createWebhookDeliveryTable()` after the existing product/packshot link table creation. Idempotent — `CREATE TABLE IF NOT EXISTS` like the others.
2. **Upgrade path** for existing Phase-3 installs: a new `upgrade-1.4.0.php` (or whatever the next semver bump is at merge time) runs the same `CREATE TABLE IF NOT EXISTS` so the migration is safe on an installed module without re-running `install`.
3. **Uninstall path**: `Installer::uninstall()` adds `qamera_webhook_delivery` to its existing `DROP TABLE IF EXISTS` statement.
4. **Rollback**: pre-merge, revert is a `git revert`. Post-merge with operator deployments: a downgrade plus running uninstall/install would drop deliveries. Recommended rollback is "keep the table, point `callback_url` upstream back to empty" — leaves data preserved for re-enable.
5. **Operator activation**: after deploying, operator updates `callback_url` on the Qamera AI panel (`/home/pracownia-qamery-ai/settings/plugin-installations/<id>`) to `https://<shop>/module/qameraai/webhook`. No automated step here — this is upstream state owned by the operator.
6. **Smoke**: trigger a packshot job from the upstream panel, watch the `QameraAiModule` log channel for the `info` line and confirm a row appears in `ps_qamera_webhook_delivery`.

## Open Questions

- **Q1: Should the front controller class live in `controllers/front/webhook.php` (PS convention) or `src/Controller/Front/`?** Initial reading is that PS's legacy dispatcher only finds front controllers in the legacy folder. To be confirmed during implementation by reading PS 8 / 9 source — if a Symfony-registered front controller is supported, prefer `src/Controller/Front/` for PSR-4 consistency. Either way, `WebhookRequestHandler` in `src/Webhook/` is where real logic lives.
- **Q2: Do we want a `processed_at` column now or wait for Phase 4.2?** Leaning wait — adding a NULL `processed_at` now means another `ALTER TABLE` in Phase 4.2 anyway when we know the exact dispatch state machine. Cheaper to add it then. To be confirmed when the Phase 4.2 design starts.
- **Q3: Header source on hosts that strip non-`HTTP_*` headers?** Plan is `getallheaders()` first, `$_SERVER['HTTP_X_QAMERA_SIGNATURE']` fallback. Need to verify on operator's actual Apache+PHP-FPM stack during smoke — if both miss the header, we'll need a `setEnvIf` hint in the `README.md`.
