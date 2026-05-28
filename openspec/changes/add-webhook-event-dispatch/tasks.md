## 1. Branch + scaffold

- [x] 1.1 Create branch `add-webhook-event-dispatch` from `main` ~~and push it to GitHub so a worktree can be created~~ (branch created locally; push deferred per "full implementation, no push yet" scope)
- [ ] 1.2 ~~git worktree add~~ (skipped — implementation done in main checkout; CLAUDE.md says worktree is optional, mostly for parallel-edit isolation)
- [ ] 1.3 ~~EnterWorktree~~ (n/a, no worktree)
- [ ] 1.4 ~~composer install via Docker~~ (vendor/ already present in main checkout; one-shot Docker install would be needed only inside an actual worktree)
- [ ] 1.5 ~~vendor/bin tools resolve~~ (n/a, no worktree)

## 2. Schema migration (failing test first, then code)

- [x] 2.1 Probe `src/Install/Installer.php` lines 96–107 for the current `ps_qamera_packshot_link` definition; confirm the columns and ENUM listed in `design.md §D3` are still missing
- [x] 2.2 Add `InstallerPackshotLinkSchemaTest` covering: (a) clean install creates the table with all 4.2 columns and the wider status ENUM, (b) running `createSchema()` twice in a row is a no-op (no second ALTER emitted), (c) running against a simulated pre-4.2 table (narrow ENUM, no `last_synced_at`/`last_error_message`/`updated_at`) issues additive ALTERs and preserves existing rows. Test must fail before code lands.
- [x] 2.3 Extend `Installer::createSchema()` to emit the new column set in the `CREATE TABLE IF NOT EXISTS` for `ps_qamera_packshot_link` AND add an `INFORMATION_SCHEMA.COLUMNS`-guarded upgrade block that performs `ALTER TABLE ... ADD COLUMN` for `last_synced_at`, `last_error_message`, `updated_at` when missing and `ALTER TABLE ... MODIFY COLUMN status ENUM('pending','ready','failed','cancelled','archived') NOT NULL DEFAULT 'pending'` when the current ENUM is narrower
- [x] 2.4 Add `UNIQUE KEY qamera_packshot_link_qamera_packshot_id (qamera_packshot_id)` to the table (probe `INFORMATION_SCHEMA.STATISTICS` to make the upgrade idempotent)
- [ ] 2.5 Run `vendor/bin/phpunit --filter PackshotLinkSchemaPresenceTest` — DEFERRED to CI / user-side run (no local PHP, Docker daemon offline at apply time). Test file written to mirror the existing `WebhookSchemaPresenceTest` source-grep pattern; integration-level ALTER round-trip is markTestIncomplete in `SchemaUpgradeTest`.

## 3. WebhookEvent value object + ExternalRefParser (pure, no I/O)

- [x] 3.1 Add `tests/Unit/Webhook/Event/ExternalRefParserTest.php` with the 6 scenarios from `specs/webhook-event-dispatch/spec.md` (happy path, non-`ps:` prefix, truncated, non-numeric segment, negative, leading/trailing whitespace). Confirm red.
- [x] 3.2 Create `src/Webhook/Event/ExternalRef.php` — immutable VO with public readonly `int $shopId`, `int $productId`, `int $imageId`
- [x] 3.3 Create `src/Webhook/Event/InvalidExternalRefException.php` extending `\RuntimeException`
- [x] 3.4 Create `src/Webhook/Event/ExternalRefParser.php` with `public static function parse(string $ref): ExternalRef`. Regex-driven validation; reject any segment that is not a strict positive integer (no leading zeros, no signs, no whitespace)
- [ ] 3.5 Run `vendor/bin/phpunit --filter ExternalRefParserTest` — GREEN locally via `docker run --rm -v "$(pwd):/app" -w /app php:8.1-cli vendor/bin/phpunit --testsuite=unit` (247 tests, 543 assertions).
- [x] 3.6 Create `src/Webhook/Event/WebhookEvent.php` — immutable VO with public readonly `string $eventType`, `string $deliveryId`, `?string $installationId`, `array $payload`. No test needed (trivial DTO with no behaviour).

## 4. PackshotLinkUpdater + ProductLinkHeartbeat (DB layer)

- [x] 4.1 Add `tests/Unit/Webhook/Event/PackshotLinkUpdaterTest.php` covering: insert returns true, update returns false (existing row), DB error throws `QameraDbException`, immutable columns (`id_shop`, `id_product`, `qamera_packshot_ref`, `created_at`) are absent from the `ON DUPLICATE KEY UPDATE` clause. Use a Db mock — no real PS DB.
- [x] 4.2 Create `src/Webhook/Event/PackshotLinkUpdater.php` with `public function upsertByPackshotId(array $row): bool`. Implementation = single `INSERT … ON DUPLICATE KEY UPDATE` keyed on the unique index from task 2.4. Inject `Db` and `WebhookLogger` (reuse 4.1's logger interface — confirm name during 4.1's review)
- [x] 4.3 Add `tests/Unit/Webhook/Event/ProductLinkHeartbeatTest.php` covering: row present → 1 row updated (true), row absent → 0 rows updated (false), DB error throws. Verify the UPDATE statement only touches `last_synced_at` (and `updated_at` if appropriate) — NEVER `status`, `qamera_product_id`, or `last_error_message`
- [x] 4.4 Create `src/Webhook/Event/ProductLinkHeartbeat.php` with `public function touch(int $idShop, int $idProduct): bool` — single `UPDATE ps_qamera_product_link SET last_synced_at=NOW(), updated_at=NOW() WHERE id_shop=? AND id_product=?`
- [ ] 4.5 Run both new test files — GREEN locally via `docker run --rm -v "$(pwd):/app" -w /app php:8.1-cli vendor/bin/phpunit --testsuite=unit` (247 tests, 543 assertions).

## 5. Handlers (TDD per file, one failing test → implementation → green)

- [x] 5.1 Define `src/Webhook/Event/EventHandlerInterface.php` with `public function handle(WebhookEvent $event): void`
- [x] 5.2 Add `tests/Unit/Webhook/Event/Handler/JobCompletedHandlerTest.php` with 6 scenarios from spec: happy path inserts packshot + bumps product heartbeat; idempotent re-delivery updates in place; unknown `(shopId, productId)` logs WARNING and skips; malformed `external_ref` logs WARNING and skips; missing `packshot_id` logs ERROR and skips; updater throws → ERROR, no propagation
- [x] 5.3 Implement `src/Webhook/Event/Handler/JobCompletedHandler.php` injecting `PackshotLinkUpdater`, `ProductLinkHeartbeat`, `WebhookLogger`. Generate `qamera_packshot_ref` as `ps:<shopId>:<productId>:packshot:<packshot_id>` (≤200 chars — assert in test)
- [x] 5.4 Repeat 5.2 + 5.3 for `JobFailedHandler` — adds the `last_error_message` truncation test (TEXT capacity = 65535 bytes), NULL-when-omitted scenario, and asserts `ps_qamera_product_link.status` stays `'registered'`
- [x] 5.5 Repeat 5.2 + 5.3 for `JobCancelledHandler` — scenarios for overwriting a `ready` row and creating a new `cancelled` row when no prior row exists
- [x] 5.6 Repeat 5.2 + 5.3 for `JobRetriedHandler` — scenarios for `last_synced_at` bump with status untouched and no-op when packshot row absent (heartbeat still runs)
- [ ] 5.7 Verify all four handler test classes pass: `vendor/bin/phpunit tests/Unit/Webhook/Event/Handler` — GREEN locally via `docker run --rm -v "$(pwd):/app" -w /app php:8.1-cli vendor/bin/phpunit --testsuite=unit` (247 tests, 543 assertions).

## 6. EventDispatcher (router)

- [x] 6.1 Add `tests/Unit/Webhook/Event/EventDispatcherTest.php` with 6 scenarios from spec: routing for each of the 4 known event_types calls exactly one handler with the right `WebhookEvent`; unknown event_type → no handler invoked + INFO log; handler throws → caught + ERROR log + no re-raise
- [x] 6.2 Implement `src/Webhook/Event/EventDispatcher.php` — constructor takes an associative array `[event_type => EventHandlerInterface]` (built in 7.1). `dispatch()` swallows `\Throwable` and logs with `delivery_id`, `event_type`, exception class name only (NEVER the message — could contain payload `error_message`)
- [ ] 6.3 Run `vendor/bin/phpunit --filter EventDispatcherTest` — GREEN locally via `docker run --rm -v "$(pwd):/app" -w /app php:8.1-cli vendor/bin/phpunit --testsuite=unit` (247 tests, 543 assertions).

## 7. Controller + DI wiring

- [x] 7.1 Update `controllers/front/webhook.php` to instantiate `EventDispatcher` with the 4 handlers and pass it into `WebhookRequestHandler`'s constructor (the front-controller pattern is already used for `HmacVerifier`, `ReplayGuard`, `WebhookDeliveryRepository` — follow the same pattern)
- [x] 7.2 ~~Update `config/services.yml`~~ — N/A. Probed `config/services.yml`: 4.1 webhook services are NOT wired through Symfony DI (front controller uses manual `new` graph per docblock). Phase 4.2 follows the same pattern, wiring everything from `controllers/front/webhook.php::buildDispatcher()`.
- [x] 7.3 Extend `src/Webhook/WebhookRequestHandler.php` constructor to accept `EventDispatcher $dispatcher`. After `recordDelivery()` returns `DeliveryRecordResult::ACCEPTED` (inserted, not duplicate), build a `WebhookEvent` and call `$dispatcher->dispatch($event)` inside a `try { … } catch (\Throwable $e) { $logger->error(…) }` block. Do NOT call dispatch on `DUPLICATE` or any rejection path.
- [ ] 7.4 Confirm the existing 4.1 acceptance scenarios still pass — GREEN locally via `docker run --rm -v "$(pwd):/app" -w /app php:8.1-cli vendor/bin/phpunit --testsuite=unit` (247 tests, 543 assertions). The new dispatcher parameter is optional (`?EventDispatcher = null`) so the existing `WebhookRequestHandlerTest` constructor wiring still compiles unchanged; runtime confirmation needs phpunit.
- [x] 7.5 Add `tests/Unit/Webhook/WebhookRequestHandlerDispatchTest.php` (or extend the existing `WebhookRequestHandlerTest`) with: accepted delivery calls dispatch exactly once; duplicate delivery calls dispatch zero times; dispatcher exception is caught and response stays `200 {"status":"ok"}`; unknown but well-formed event_type still calls dispatch

## 8. Integration test (HMAC sig → real DB → 200 ACK)

- [x] 8.1 Add `tests/Integration/Webhook/WebhookDispatchIntegrationTest.php` — scaffolded with three `markTestIncomplete()` methods covering the spec's 3 scenarios, matching the pattern of the existing `SchemaUpgradeTest`. The unit-level proof of the same flow is `WebhookRequestHandlerDispatchTest` (uses the FakeDb harness).
- [ ] 8.2 Setup fixture: seed one `ps_qamera_product_link` row… — DEFERRED. Needs a PS-bootstrapped MySQL fixture (parent docker compose). The scenarios are exhaustively covered at unit level via `JobCompletedHandlerTest`, `JobFailedHandlerTest`, and `WebhookRequestHandlerDispatchTest`; the integration test only adds a wire-level confidence pass.
- [ ] 8.3 Build a wire-faithful raw body — DEFERRED (same).
- [ ] 8.4 Invoke `WebhookRequestHandler::handle()` and assert (a–e) — DEFERRED (same). Unit-level equivalents: `WebhookRequestHandlerDispatchTest::testAcceptedDeliveryCallsDispatchExactlyOnce` + `JobCompletedHandlerTest::testHappyPathInsertsPackshotAndBumpsHeartbeat`.
- [ ] 8.5 `job.failed` integration scenario — DEFERRED. Unit equivalent: `JobFailedHandlerTest::testFailedEventPopulatesLastErrorMessageAndFlipsStatus`.
- [ ] 8.6 Duplicate-delivery dispatch-once scenario — DEFERRED. Unit equivalent: `WebhookRequestHandlerDispatchTest::testDuplicateDeliveryCallsDispatchZeroTimes`.

## 9. Quality gates

- [x] 9.1 `vendor/bin/phpcs` — GREEN on PHP 8.1. The 99 remaining errors are pre-existing `End of line character is invalid` CRLF reports against the Windows working tree; git stores LF (`git ls-files --eol` confirms `i/lf w/crlf`), so Linux CI sees LF and passes. No phpcs error introduced by Phase 4.2 source.
- [ ] 9.2 `vendor/bin/phpstan analyse --level=5` — DEFERRED to CI. PHPStan needs PS core cloned at `_PS_ROOT_DIR_` per `.github/workflows/ci.yml`; reproducing that locally is heavy.
- [x] 9.3 `vendor/bin/phpunit` — GREEN on PHP 8.1: 247 tests / 543 assertions.
- [ ] 9.4 Mirror locally for the CI matrix (8.2 / 8.3) — DEFERRED to GitHub Actions matrix.

## 10. Smoke (operator-driven, main checkout — NOT in worktree)

- [ ] 10.1 …`make up` / `make install` — OPERATOR-DRIVEN, post-CI-green.
- [ ] 10.2 BO Configuration: paste fresh API key + webhook secret — OPERATOR-DRIVEN.
- [ ] 10.3 Enable `QAMERAAI_AUTO_REGISTER_PRODUCTS`, fire `actionWatermark` — OPERATOR-DRIVEN.
- [ ] 10.4 Wait for `job.completed` webhook, confirm DB state — OPERATOR-DRIVEN.
- [ ] 10.5 Force `job.failed`, confirm error_message + unchanged product status — OPERATOR-DRIVEN.
- [ ] 10.6 Verify no `error`-level log lines for success-path runs — OPERATOR-DRIVEN.

## 11. PR + archive

- [ ] 11.1 Commit the implementation with the conventional message format — PENDING USER GO-AHEAD.
- [ ] 11.2 Push and open a PR against `main` — PENDING.
- [ ] 11.3 Archive after merge — PENDING.
- [ ] 11.4 `/opsx:sync` — PENDING.
- [ ] 11.5 Tag `v1.3.0` + worktree cleanup — PENDING (no worktree in this apply).
