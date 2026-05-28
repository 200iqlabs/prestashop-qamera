## 1. Branch + scaffold

- [ ] 1.1 Create branch `add-webhook-event-dispatch` from `main` and push it to GitHub so a worktree can be created
- [ ] 1.2 From the main checkout, run `git worktree add .claude/worktrees/add-webhook-event-dispatch add-webhook-event-dispatch` per CLAUDE.md "Git worktrees" procedure
- [ ] 1.3 Enter the worktree via `EnterWorktree path=…` (NOT `cd`) so harness state registers correctly
- [ ] 1.4 Install composer deps inside the worktree via one-shot docker: `docker run --rm -v "<abs-worktree>:/app" -w /app composer:2 install --no-interaction --prefer-dist`
- [ ] 1.5 Confirm `vendor/bin/phpunit`, `vendor/bin/phpcs`, `vendor/bin/phpstan` resolve from the worktree

## 2. Schema migration (failing test first, then code)

- [ ] 2.1 Probe `src/Install/Installer.php` lines 96–107 for the current `ps_qamera_packshot_link` definition; confirm the columns and ENUM listed in `design.md §D3` are still missing
- [ ] 2.2 Add `InstallerPackshotLinkSchemaTest` covering: (a) clean install creates the table with all 4.2 columns and the wider status ENUM, (b) running `createSchema()` twice in a row is a no-op (no second ALTER emitted), (c) running against a simulated pre-4.2 table (narrow ENUM, no `last_synced_at`/`last_error_message`/`updated_at`) issues additive ALTERs and preserves existing rows. Test must fail before code lands.
- [ ] 2.3 Extend `Installer::createSchema()` to emit the new column set in the `CREATE TABLE IF NOT EXISTS` for `ps_qamera_packshot_link` AND add an `INFORMATION_SCHEMA.COLUMNS`-guarded upgrade block that performs `ALTER TABLE ... ADD COLUMN` for `last_synced_at`, `last_error_message`, `updated_at` when missing and `ALTER TABLE ... MODIFY COLUMN status ENUM('pending','ready','failed','cancelled','archived') NOT NULL DEFAULT 'pending'` when the current ENUM is narrower
- [ ] 2.4 Add `UNIQUE KEY qamera_packshot_link_qamera_packshot_id (qamera_packshot_id)` to the table (probe `INFORMATION_SCHEMA.STATISTICS` to make the upgrade idempotent)
- [ ] 2.5 Run `vendor/bin/phpunit --filter InstallerPackshotLinkSchemaTest` — must go red before 2.3/2.4, green after

## 3. WebhookEvent value object + ExternalRefParser (pure, no I/O)

- [ ] 3.1 Add `tests/Unit/Webhook/Event/ExternalRefParserTest.php` with the 6 scenarios from `specs/webhook-event-dispatch/spec.md` (happy path, non-`ps:` prefix, truncated, non-numeric segment, negative, leading/trailing whitespace). Confirm red.
- [ ] 3.2 Create `src/Webhook/Event/ExternalRef.php` — immutable VO with public readonly `int $shopId`, `int $productId`, `int $imageId`
- [ ] 3.3 Create `src/Webhook/Event/InvalidExternalRefException.php` extending `\RuntimeException`
- [ ] 3.4 Create `src/Webhook/Event/ExternalRefParser.php` with `public static function parse(string $ref): ExternalRef`. Regex-driven validation; reject any segment that is not a strict positive integer (no leading zeros, no signs, no whitespace)
- [ ] 3.5 Run `vendor/bin/phpunit --filter ExternalRefParserTest` — must go green
- [ ] 3.6 Create `src/Webhook/Event/WebhookEvent.php` — immutable VO with public readonly `string $eventType`, `string $deliveryId`, `?string $installationId`, `array $payload`. No test needed (trivial DTO with no behaviour).

## 4. PackshotLinkUpdater + ProductLinkHeartbeat (DB layer)

- [ ] 4.1 Add `tests/Unit/Webhook/Event/PackshotLinkUpdaterTest.php` covering: insert returns true, update returns false (existing row), DB error throws `QameraDbException`, immutable columns (`id_shop`, `id_product`, `qamera_packshot_ref`, `created_at`) are absent from the `ON DUPLICATE KEY UPDATE` clause. Use a Db mock — no real PS DB.
- [ ] 4.2 Create `src/Webhook/Event/PackshotLinkUpdater.php` with `public function upsertByPackshotId(array $row): bool`. Implementation = single `INSERT … ON DUPLICATE KEY UPDATE` keyed on the unique index from task 2.4. Inject `Db` and `WebhookLogger` (reuse 4.1's logger interface — confirm name during 4.1's review)
- [ ] 4.3 Add `tests/Unit/Webhook/Event/ProductLinkHeartbeatTest.php` covering: row present → 1 row updated (true), row absent → 0 rows updated (false), DB error throws. Verify the UPDATE statement only touches `last_synced_at` (and `updated_at` if appropriate) — NEVER `status`, `qamera_product_id`, or `last_error_message`
- [ ] 4.4 Create `src/Webhook/Event/ProductLinkHeartbeat.php` with `public function touch(int $idShop, int $idProduct): bool` — single `UPDATE ps_qamera_product_link SET last_synced_at=NOW(), updated_at=NOW() WHERE id_shop=? AND id_product=?`
- [ ] 4.5 Run both new test files — must go green

## 5. Handlers (TDD per file, one failing test → implementation → green)

- [ ] 5.1 Define `src/Webhook/Event/EventHandlerInterface.php` with `public function handle(WebhookEvent $event): void`
- [ ] 5.2 Add `tests/Unit/Webhook/Event/Handler/JobCompletedHandlerTest.php` with 6 scenarios from spec: happy path inserts packshot + bumps product heartbeat; idempotent re-delivery updates in place; unknown `(shopId, productId)` logs WARNING and skips; malformed `external_ref` logs WARNING and skips; missing `packshot_id` logs ERROR and skips; updater throws → ERROR, no propagation
- [ ] 5.3 Implement `src/Webhook/Event/Handler/JobCompletedHandler.php` injecting `PackshotLinkUpdater`, `ProductLinkHeartbeat`, `WebhookLogger`. Generate `qamera_packshot_ref` as `ps:<shopId>:<productId>:packshot:<packshot_id>` (≤200 chars — assert in test)
- [ ] 5.4 Repeat 5.2 + 5.3 for `JobFailedHandler` — adds the `last_error_message` truncation test (TEXT capacity = 65535 bytes), NULL-when-omitted scenario, and asserts `ps_qamera_product_link.status` stays `'registered'`
- [ ] 5.5 Repeat 5.2 + 5.3 for `JobCancelledHandler` — scenarios for overwriting a `ready` row and creating a new `cancelled` row when no prior row exists
- [ ] 5.6 Repeat 5.2 + 5.3 for `JobRetriedHandler` — scenarios for `last_synced_at` bump with status untouched and no-op when packshot row absent (heartbeat still runs)
- [ ] 5.7 Verify all four handler test classes pass: `vendor/bin/phpunit tests/Unit/Webhook/Event/Handler`

## 6. EventDispatcher (router)

- [ ] 6.1 Add `tests/Unit/Webhook/Event/EventDispatcherTest.php` with 6 scenarios from spec: routing for each of the 4 known event_types calls exactly one handler with the right `WebhookEvent`; unknown event_type → no handler invoked + INFO log; handler throws → caught + ERROR log + no re-raise
- [ ] 6.2 Implement `src/Webhook/Event/EventDispatcher.php` — constructor takes an associative array `[event_type => EventHandlerInterface]` (built in 7.1). `dispatch()` swallows `\Throwable` and logs with `delivery_id`, `event_type`, exception class name only (NEVER the message — could contain payload `error_message`)
- [ ] 6.3 Run `vendor/bin/phpunit --filter EventDispatcherTest` — must go green

## 7. Controller + DI wiring

- [ ] 7.1 Update `controllers/front/webhook.php` to instantiate `EventDispatcher` with the 4 handlers and pass it into `WebhookRequestHandler`'s constructor (the front-controller pattern is already used for `HmacVerifier`, `ReplayGuard`, `WebhookDeliveryRepository` — follow the same pattern)
- [ ] 7.2 Update `config/services.yml` if it carries any of the existing webhook services — add the new event-dispatch services alongside (probe the file first; if it doesn't currently wire 4.1's services, this task is a no-op)
- [ ] 7.3 Extend `src/Webhook/WebhookRequestHandler.php` constructor to accept `EventDispatcher $dispatcher`. After `recordDelivery()` returns `DeliveryRecordResult::ACCEPTED` (inserted, not duplicate), build a `WebhookEvent` and call `$dispatcher->dispatch($event)` inside a `try { … } catch (\Throwable $e) { $logger->error(…) }` block. Do NOT call dispatch on `DUPLICATE` or any rejection path.
- [ ] 7.4 Confirm the existing 4.1 acceptance scenarios still pass — duplicate path still returns `{"status":"duplicate"}`, all rejection paths still skip dispatch
- [ ] 7.5 Add `tests/Unit/Webhook/WebhookRequestHandlerDispatchTest.php` (or extend the existing `WebhookRequestHandlerTest`) with: accepted delivery calls dispatch exactly once; duplicate delivery calls dispatch zero times; dispatcher exception is caught and response stays `200 {"status":"ok"}`; unknown but well-formed event_type still calls dispatch

## 8. Integration test (HMAC sig → real DB → 200 ACK)

- [ ] 8.1 Add `tests/Integration/Webhook/WebhookDispatchIntegrationTest.php` that uses the same DB harness `WebhookDeliveryRepository` tests use (or extend that harness if missing)
- [ ] 8.2 Setup fixture: seed one `ps_qamera_product_link` row for `(id_shop=1, id_product=42)` with `status='registered'`, `qamera_product_id='abc-uuid'`, `last_synced_at='2026-05-27 10:00:00'`
- [ ] 8.3 Build a wire-faithful raw body for `job.completed` (`{"event_type":"job.completed","delivery_id":"D1","installation_id":"i1","payload":{"external_ref":"ps:1:42:image:7","packshot_id":"packshot-uuid","job_id":"job-uuid"}}`) and sign it with a known test secret
- [ ] 8.4 Invoke `WebhookRequestHandler::handle()` and assert: (a) `qamera_webhook_delivery` row inserted with `status='accepted'`, (b) `ps_qamera_packshot_link` row exists with `qamera_packshot_id='packshot-uuid'`, `status='ready'`, `qamera_job_id='job-uuid'`, `id_shop=1`, `id_product=42`, (c) `ps_qamera_product_link.last_synced_at > '2026-05-27 10:00:00'`, (d) `ps_qamera_product_link.status='registered'` AND `qamera_product_id='abc-uuid'` unchanged, (e) response is `200` with body `{"status":"ok"}`
- [ ] 8.5 Add a `job.failed` integration scenario asserting `last_error_message` populated, `status='failed'`, product row's `status` unchanged
- [ ] 8.6 Add a duplicate-delivery scenario asserting the dispatcher is invoked exactly once (first delivery) and zero times (second delivery)

## 9. Quality gates

- [ ] 9.1 `vendor/bin/phpcs` — clean on `src/Webhook/Event/` and the modified controller/handler/installer
- [ ] 9.2 `vendor/bin/phpstan analyse --level=5` — clean on `src/Webhook/Event/`, `src/Webhook/WebhookRequestHandler.php`, `controllers/front/webhook.php`
- [ ] 9.3 `vendor/bin/phpunit` — full suite green
- [ ] 9.4 Mirror locally for the CI matrix: re-run 9.1/9.2/9.3 inside `php:8.2-cli` and `php:8.3-cli` one-shot containers per CLAUDE.md "Git worktrees" step 4

## 10. Smoke (operator-driven, main checkout — NOT in worktree)

- [ ] 10.1 Exit the worktree, return to the main checkout, `git checkout add-webhook-event-dispatch`, then from the parent `qameraai-prestashop/` shell run `make up` and `make install`
- [ ] 10.2 In the BO Configuration page, paste fresh `QAMERAAI_API_KEY` and `QAMERAAI_WEBHOOK_SECRET` from the Qamera AI panel (see CLAUDE.md "Credentials for smoke testing")
- [ ] 10.3 Enable `QAMERAAI_AUTO_REGISTER_PRODUCTS`, edit a product image to fire `actionWatermark`, observe the upstream `registerImage` call in the qamera.ai panel
- [ ] 10.4 Wait for the `job.completed` webhook (≤30 s); confirm via `make shell` + `mysql` that `ps_qamera_webhook_delivery` shows an `accepted` row AND `ps_qamera_packshot_link` shows a `ready` row matching the same product
- [ ] 10.5 Force a `job.failed` via the saas-platform staging knob; confirm `ps_qamera_packshot_link.status='failed'` + `last_error_message` populated + `ps_qamera_product_link.status` unchanged
- [ ] 10.6 Verify no `error`-level log lines appear in `var/logs/` for the success-path runs

## 11. PR + archive

- [ ] 11.1 Commit the worktree work with the conventional message format from prior phases (`feat(webhook-event-dispatch): Phase 4.2 — dispatch verified deliveries to product/packshot bookkeeping`)
- [ ] 11.2 Push and open a PR against `main`; reference the proposal/design files in the PR body
- [ ] 11.3 After merge (squash), from the main checkout: `git fetch && git checkout main && git pull && /opsx:archive add-webhook-event-dispatch`
- [ ] 11.4 Run `/opsx:sync` (or accept the bulk-archive's sync prompt) so the new `webhook-event-dispatch` capability lands in `openspec/specs/` and the `webhook-handler` + `prestashop-module-bootstrap` deltas merge into their main specs
- [ ] 11.5 Optional: tag `v1.3.0` and clean up the worktree (`git worktree remove .claude/worktrees/add-webhook-event-dispatch`)
