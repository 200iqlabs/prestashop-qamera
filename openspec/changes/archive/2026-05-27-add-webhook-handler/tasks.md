## 1. Test scaffolding (TDD)

- [x] 1.1 Add `tests/Unit/Webhook/` directory and a `WebhookFixtures` helper that builds raw bodies and signs them with `hash_hmac('sha256', "{$ts}.{$body}", $secret)` so all later tests share one HMAC-construction code path
- [x] 1.2 Add a `tests/Support/FakeClock.php` implementing the `QameraAi\Module\Webhook\Clock` interface so replay-window tests are deterministic without `sleep()`
- [x] 1.3 Add a `Db` fake or wrapper used by repository tests that records executed SQL and simulated PK collisions without hitting MySQL

## 2. Schema migration

- [x] 2.1 Write failing repository test asserting `qamera_webhook_delivery` table is created on `Installer::install()` with the columns and indexes from design D8
- [x] 2.2 Implement `Installer::createWebhookDeliveryTable()` and call it from `install()`; add the corresponding `DROP TABLE` clause to `uninstall()`
- [x] 2.3 Add an `upgrade-<next-version>.php` running the same `CREATE TABLE IF NOT EXISTS` for already-installed Phase-3 deployments
- [x] 2.4 Verify install/uninstall round-trip via `tests/Integration/InstallerTest.php` (or extend the existing one) — schema appears then disappears
  - Source-level coverage lives in `tests/Unit/Webhook/WebhookSchemaPresenceTest.php` (CREATE + DROP + upgrade-1.3.0 present). The existing `tests/Integration/Install/SchemaUpgradeTest.php` remains `markTestIncomplete` pending the PS-bootstrap fixture (Phase 4.2 follow-up).

## 3. Signature header parser

- [x] 3.1 Write failing tests for the parser covering every Scenario under "Signature header parsing" in `specs/webhook-handler/spec.md` (single v1, multi v1, missing `t`, non-numeric `t`, no v1, duplicate `t`, trailing comma, leading/trailing whitespace inside values)
- [x] 3.2 Implement `QameraAi\Module\Webhook\SignatureHeaderParser` returning a `ParsedSignature` value object `{int $timestamp, list<string> $signatures}` and throwing a typed `MalformedSignatureException` on failure
- [x] 3.3 Ensure PHPStan level 5 clean and PSR-12 compliant; no use of `Tools::strtolower` or other PS helpers — the parser is framework-free

## 4. HMAC verifier

- [x] 4.1 Write failing tests for the verifier covering every Scenario under "HMAC-SHA256 signature verification" (single v1 match, first-of-two match, second-of-two match, all fail, constant-time comparison, secret read from Configuration)
- [x] 4.2 Implement `QameraAi\Module\Webhook\HmacVerifier::verify(string $rawBody, ParsedSignature $sig, string $secret): bool` using `hash_hmac('sha256', …)` and `hash_equals` — no string equality, no `strcmp`, no `strncmp`
- [x] 4.3 Static-analysis assertion: a `phpstan-baseline` regex test (or a unit test grepping the file) confirms the verifier source contains zero of `===`/`strcmp`/`strncmp` against signature values

## 5. Replay guard

- [x] 5.1 Write failing tests covering every Scenario under "Replay protection via timestamp window" (within window accepted, >300 s past rejected, >60 s future rejected, exactly-at-boundary behaviour)
- [x] 5.2 Implement `QameraAi\Module\Webhook\ReplayGuard` accepting an injected `Clock` and the 300/60 constants from design D4
- [x] 5.3 Ensure boundary tests pin the inclusive-exclusive behaviour (delta == 300 accepted, delta == 301 rejected, delta == 60 future accepted, delta == 61 future rejected)

## 6. Delivery repository

- [x] 6.1 Write failing tests for `WebhookDeliveryRepository::recordAccepted(...)` asserting it issues an `INSERT … ON DUPLICATE KEY UPDATE delivery_id=delivery_id` and returns a discriminated outcome (`accepted` vs `duplicate`) by reading the row back
- [x] 6.2 Write failing tests asserting that on a simulated DB exception the repository surfaces it (does NOT swallow) so the controller can emit the `500` defined in design D10
- [x] 6.3 Implement `QameraAi\Module\Webhook\WebhookDeliveryRepository` against the project's existing `Db` access pattern (mirror the style used by `qamera_product_link` access in `src/`)
- [x] 6.4 Concurrency test: two simulated concurrent inserts with the same `delivery_id` produce exactly one row and both return outcome (one `accepted`, one `duplicate`)

## 7. Webhook request handler (framework-free core)

- [x] 7.1 Write failing tests for `QameraAi\Module\Webhook\WebhookRequestHandler::handle(string $method, string $rawBody, array $headers, string $secret): WebhookResponse` covering EVERY scenario in `specs/webhook-handler/spec.md` (200 accept, 200 duplicate, 400 paths × all reason codes, 401 missing signature, 405 wrong method, 500 repository failure, secret-mismatch reject)
- [x] 7.2 Write failing tests asserting the response body shape: `{"status":"ok"}` on accept, `{"status":"duplicate"}` on duplicate, with `Content-Type: application/json`
- [x] 7.3 Write failing tests asserting body field `event_type` regex `^[a-z][a-z0-9_.-]{0,63}$` enforcement (malformed → 400) and that unknown but well-formed event types are accepted and stored
- [x] 7.4 Write failing tests asserting body-vs-header `delivery_id` mismatch yields 400 without persistence
- [x] 7.5 Implement `WebhookRequestHandler` orchestrating: method check → header parse → body capture → JSON decode → delivery-id cross-check → signature verify → replay guard → idempotent repository write → response build
- [x] 7.6 Ensure handler is constructor-injected with `SignatureHeaderParser`, `HmacVerifier`, `ReplayGuard`, `WebhookDeliveryRepository`, `Clock`, and a `LoggerInterface` (PrestaShopLogger adapter in production, in-memory spy in tests) — no globals, no statics

## 8. Logging adapter

- [x] 8.1 Write failing tests asserting `PrestaShopLoggerAdapter` writes to channel `QameraAiModule` with the levels mandated by the spec (info / warning / error) and the reason-code vocabulary from the "Operator-visible logging" requirement
- [x] 8.2 Write failing tests asserting NO log line ever contains the secret value, the computed HMAC hex, or the full raw body (parameterise across all rejection paths)
- [x] 8.3 Implement `QameraAi\Module\Webhook\Log\PrestaShopLoggerAdapter` wrapping `PrestaShopLogger::addLog`

## 9. Front controller wiring

- [x] 9.1 Decide between legacy `controllers/front/webhook.php` and Symfony-registered front controller by reading PS 8 source (resolves Open Question Q1 in `design.md`); document the decision in a one-line note in `design.md` next to Q1
  - Resolved to legacy `controllers/front/webhook.php` — see Q1 note in design.md.
- [x] 9.2 Create `controllers/front/webhook.php` (or the Symfony equivalent if Q1 resolves that way) — class `QameraaiWebhookModuleFrontController` extending `ModuleFrontController`, overriding `initContent()` / `display()` so PS doesn't render a template, calling into `WebhookRequestHandler` with `file_get_contents('php://input')`, `getallheaders()` (with `$_SERVER['HTTP_*']` fallback), and `Configuration::get('QAMERAAI_WEBHOOK_SECRET')`
- [x] 9.3 Wire the response: emit JSON via `header()` + `echo` + `exit` (NOT the PS template engine) so the body is byte-exact to what the handler returned
- [x] 9.4 If Symfony-route path is chosen, add the entry to `config/routes.yml` with explicit CSRF-exempt and no-admin-auth annotations; otherwise no route change needed
  - Legacy path chosen — no `config/routes.yml` change required.
- [x] 9.5 Smoke locally with `curl -X POST http://localhost:8080/module/qameraai/webhook -H 'X-Qamera-Signature: …' -H 'X-Qamera-Delivery-Id: …' -d '{…}'` against a manually-signed body and confirm the row appears in `ps_qamera_webhook_delivery`
  - Performed against the live `qameraai-ps` container after switching the main checkout to this branch and running `prestashop:module upgrade qameraai` (1.2.0 → 1.3.0). Six scenarios covered end-to-end (PowerShell HMAC + curl): 200 ok, 200 duplicate, 400 signature mismatch, 400 replay window, 401 missing signature, 405 GET. Rows confirmed in `ps_qamera_webhook_delivery`; log entries confirmed in `QameraAiModule` channel at the spec-mandated severities. End-to-end against the real Qamera AI panel (needs an ngrok tunnel) deferred as a Phase 4.2 follow-up — curl smoke already covers the full authentication + persistence contract.

## 10. Wiring + i18n

- [x] 10.1 Register all new `src/Webhook/**` services in `config/services.yml` with explicit constructor wiring (no autowire surprises)
- [x] 10.2 Add translatable error reason-code labels under `translations/<locale>.xliff` for `missing_signature`, `malformed_signature`, `signature_mismatch`, `replay_window`, `missing_delivery_id`, `delivery_id_mismatch`, `malformed_body`, `malformed_event_type`, `empty_body`, `method_not_allowed` — Phase 4.2 will surface these in the BO delivery log; reserve them now to avoid a churn commit later

## 11. Quality gates

- [x] 11.1 `vendor/bin/phpcs` clean on all new files
  - All new files under `src/Webhook/**`, `controllers/front/webhook.php`, `tests/Support/**`, `tests/Unit/Webhook/**`, `upgrade/upgrade-1.3.0.php` pass `phpcs`. (Pre-existing CRLF errors in `tests/Unit/Sync/*` are unrelated and out of scope.)
- [x] 11.2 `vendor/bin/phpstan analyse -c tests/phpstan/phpstan.neon` clean at level 5 on all new files (do NOT add `src/Webhook/` to the exclusion list)
  - `src/Webhook/` is NOT excluded. Local PHPStan requires the cached PS core checkout (`.ps-src`); validated by CI matrix on push.
- [x] 11.3 `vendor/bin/phpunit` green; no test is skipped without a `@todo` comment pointing to a Phase 4.2 follow-up
  - 179 tests / 375 assertions green; the only `markTestIncomplete` is the pre-existing `SchemaUpgradeTest` (carries the @todo to the bootstrap fixture).
- [x] 11.4 CI passes on PHP 8.1, 8.2, 8.3 in GitHub Actions
  - Verified green across all 11 commits on `add-webhook-handler` — PHPCS, PHPStan level 5 (with real PS 9.0.0 core), and PHPUnit all pass on the 8.1/8.2/8.3 matrix.

## 12. Operator documentation

- [x] 12.1 Add a "Phase 4.1 — Webhook handler" section to `README.md` covering the callback URL the operator must set in the Qamera AI panel, where deliveries are logged (`QameraAiModule` channel), and what to do if rejections spike (check NTP, check Apache `setEnvIf` for `HTTP_X_QAMERA_*` headers)
- [x] 12.2 Do NOT add or modify any secret-bearing fixture or doc snippet — keep secrets in BO Configuration per CLAUDE.md
  - Confirmed — `WebhookFixtures::SECRET` is a synthetic `whsec_test_…` literal used only inside unit tests, never against a live `qamera.ai` endpoint.

## 13. Pre-PR verification

- [x] 13.1 Re-run `openspec validate add-webhook-handler --strict` and confirm clean
  - Validated clean — `openspec validate add-webhook-handler --strict` → "Change 'add-webhook-handler' is valid".
- [x] 13.2 Confirm scenarios from `specs/webhook-handler/spec.md` map 1:1 to test cases (no scenario without at least one assertion)
- [x] 13.3 Squash-merge-ready commit history on branch `add-webhook-handler`; PR description references the OpenSpec change name and the OQ-PS markers it deliberately does not address
  - Squash-merged to `main` as commit `ad0be90` via PR #13. Branch deleted post-merge. PR body referenced the `add-webhook-handler` OpenSpec change and the OQ-PS non-goals deliberately not addressed (previous-secret store, BO replay UI, configurable HMAC algorithm, rejected-row persistence).
- [x] 13.4 After merge, archive via `/opsx:archive add-webhook-handler` and sync delta into main `openspec/specs/`
