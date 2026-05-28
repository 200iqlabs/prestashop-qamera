## 1. Preflight + branch setup

- [ ] 1.1 Verify `SubmitJobRequest` DTO already reflects the session-envelope shape (`session_config` +
      `subjects[]`) — `grep -n session_config src/Api/Dto/SubmitJobRequest.php`
- [ ] 1.2 Verify upstream `add-plugin-session-lifecycle` change is deployed to the live env used for smoke
      (operator confirms; no automated check)
- [x] 1.3 Create branch `add-packshots-ui` off `main`, push so worktree workflow can pick it up
- [ ] 1.4 Set up `.claude/worktrees/add-packshots-ui` per CLAUDE.md "Git worktrees" protocol; run
      `composer install` inside via the documented one-shot docker command
      (DEFERRED — Slice A done in main checkout via one-shot Docker phpunit/phpcs)

## 2. API client: listMannequinModels + DTO

- [x] 2.1 Add `src/Api/Dto/MannequinModel.php` mirroring `Scenery` shape (`id`, `name`, `thumbnail`,
      `source`, `status`, `createdAt`)
- [x] 2.2 Add `QameraApiClient::listMannequinModels(): array` — wrapper key `models`, same retry/header
      pattern as `listSceneries()`
- [x] 2.3 Add unit tests via `MockHandler`: happy path, wrong wrapper key surfaces `ValidationException`,
      empty list returns `[]`
- [ ] 2.4 Update contract test fixtures snapshot under `tests/Fixtures/` for `/models`
      (DEFERRED — no `tests/Fixtures/` directory exists yet; introduce in Slice C when contract test
      is touched)

## 3. Reference cache decorator

- [x] 3.1 Add `src/Api/Cache/ReferenceCache.php` — filesystem-backed (deliberate simplification vs. spec
      D4 — `\Cache::getInstance()` indirection skipped; see file header comment), key shape
      `qameraai:ref:<endpoint>:<sha256(api_key)[0:16]>`
- [x] 3.2 Add `src/Api/Cache/CachedReferenceClient.php` decorator implementing the six reference methods
      with the TTL table from `qamera-api-client` delta spec
- [x] 3.3 Wire `CachedReferenceClient` into `config/services.yml` via
      `CachedReferenceClientFactory` (request-time `QAMERAAI_API_KEY` read);
      `ReferenceCache` wired with `_PS_CACHE_DIR_` constant
- [x] 3.4 Unit tests: cache hit within TTL, miss after TTL expiry, different API keys do not share entries
- [x] 3.5 Filesystem cleanup helper for tests (`tearDown` deletes `_PS_CACHE_DIR_ . 'qameraai/reference/'`)

## 4. ps_qamera_packshot_job table + installer

- [x] 4.1 Add `install()` DDL in `src/Install/Installer.php`: `ps_qamera_packshot_job` per the
      `packshot-jobs` spec (PK, UNIQUE on `qamera_job_id`, FK CASCADE to
      `ps_qamera_product_link.id_link`, indexes on `(id_shop,id_product)` and `(status,submitted_at)`).
      DEVIATION: `packshot_external_ref` is NOT unique — spec was self-contradictory (UNIQUE would
      break the "10 rows from imagesCount=2 × 5 products" scenario). See Installer comment.
- [x] 4.1b Add `qamera_image_id CHAR(36) NULL` migration to `ps_qamera_product_link` (Slice A scope
      extension — spec assumed this column existed; gap closed per user decision)
- [x] 4.2 Add `uninstall()` DROP for the same table, run BEFORE dropping `ps_qamera_product_link` so the
      FK doesn't block
- [x] 4.3 Ensure idempotent install via `CREATE TABLE IF NOT EXISTS` (matches existing pattern)
- [ ] 4.4 Manual smoke from main checkout: `make install` → `SHOW CREATE TABLE ps_qamera_packshot_job\G`
      to confirm DDL matches spec (DEFERRED — Slice C runtime smoke)
- [ ] 4.5 Manual smoke: `make uninstall` → confirm table gone (DEFERRED — Slice C)

## 5. PackshotJobRepository

- [x] 5.1 Add `src/Packshot/PackshotJobRow.php` value object (immutable, all spec columns)
- [x] 5.2 Add `src/Packshot/PackshotJobRepository.php` with `findByJobId`, `findByExternalRef`,
      `listForGrid(JobsGridFilters)`, `insertBatch(array $rows): void`,
      `upsertFromWebhook(PackshotJobWebhookUpdate)`
- [x] 5.3 `insertBatch` uses single multi-row `INSERT … ON DUPLICATE KEY UPDATE` on `qamera_job_id`
- [x] 5.4 `listForGrid` joins `ps_product_lang` for localised product name, respects `id_lang`
- [x] 5.5 All queries use prepared statements via `Db::getInstance()->execute()` (escape-based; matches
      existing `PackshotLinkUpdater` pattern — PS has no parametrised-query API)
- [x] 5.6 Unit tests via `RecordingDb`: insertBatch SQL shape, idempotent ON DUPLICATE KEY UPDATE,
      upsertFromWebhook INSERT vs UPDATE path, listForGrid join + status filter

## 6. PackshotJobSubmitter

- [x] 6.1 Add `src/Packshot/SubmitFormInput.php` value object collecting the form fields
- [x] 6.2 Add `src/Packshot/SubmitResult.php` (sessions_submitted, sessions_failed, jobs_persisted,
      per-chunk failures with reasons)
- [x] 6.3 Add `src/Packshot/PackshotJobSubmitter.php` — `submit(SubmitFormInput): SubmitResult`
  - generates UUID v4 per subject for `packshot_external_ref` (`Ramsey\Uuid\Uuid::uuid4()`)
  - chunks subjects into ≤100 per session
  - per chunk: builds `SubmitJobRequest` with `auto_register_packshot=true`; Idempotency-Key added
    by the Guzzle middleware on `POST /jobs`
  - on success: maps response → rows, calls `PackshotJobRepository::insertBatch`
  - on failure: aggregates chunk-level error info, no DB writes for failed chunk
- [x] 6.4 Unit tests with stub `QameraApiClient`: single subject, bulk 5×2, 503 leaves DB unchanged,
      422 leaves DB unchanged, 247-subject chunking emits 3 calls of sizes [100,100,47], partial chunk
      failure reports correctly
- [x] 6.5 Test confirming `auto_register_packshot=true` and ref format
      `^ps:\d+:\d+:packshot:[0-9a-f]{8}-[0-9a-f]{4}-…$`

## 7. CostCalculator

- [x] 7.1 Add `src/Packshot/CostCalculator.php` — `estimate(string $aiModel, int $imagesCount, int
      $subjectCount): ?int` reading from cached `Pricing` (via `CachedReferenceClient::getPricing()`)
- [x] 7.2 Unit tests: known model → product of three numbers; unknown model / wrong job_type /
      malformed ai_model / zero counts → `null`

## 8. PackshotJobUpdater + webhook handler wiring

- [x] 8.1 Add `src/Packshot/PackshotJobUpdater.php` — `upsert(...)` with status mapping per
      `webhook-handler` delta spec
- [x] 8.2 Add `src/Packshot/PackshotJobWebhookUpdate.php` value object
- [x] 8.3 Extend `JobCompletedHandler` to inject `PackshotJobUpdater` and call `upsert(...)` with the
      mapped fields (existing `PackshotLinkUpdater` + `ProductLinkHeartbeat` paths unchanged)
- [x] 8.4 Same extension in `JobFailedHandler` (`last_error_message`), `JobRetriedHandler`
      (`in_progress`), `JobCancelledHandler` (`cancelled`)
- [x] 8.5 Pre-submit race: if row absent, insert using parsed `external_ref` → product link lookup
      (via new `PackshotExternalRefParser` + `SyncedProductLinkLookup::findIdLink`)
- [x] 8.6 Unknown payload status (event_type) → map to `pending`, log WARNING with the unknown value,
      do not throw
- [x] 8.7 Wire updater + repository + lookup in `config/services.yml`; controllers/front/webhook.php
      builds the dispatcher with the new updater injected
- [x] 8.8 Unit tests in `PackshotJobUpdaterTest`: existing-row upsert, pre-submit insert path,
      unknown-status warning, malformed-ref warning, missing-ref noop; existing handler tests
      patched to inject `FakePackshotJobUpdater`

## 9. BO controllers + routes

- [ ] 9.1 `controllers/admin/AdminQameraAiProductsController.php` shim — NOT NEEDED.
      PS's Symfony admin routing handles `_legacy_controller: AdminQameraAi*` without a
      shim file (same pattern as the existing `AdminQameraAiConfiguration`).
- [ ] 9.2 `controllers/admin/AdminQameraAiJobsController.php` shim — NOT NEEDED (same reason)
- [x] 9.3 Add `src/Controller/Admin/ProductsGridController.php` extending
      `FrameworkBundleAdminController` — `indexAction(Request)` paginates link rows from
      `SyncedProductLinkLookup::listForGrid`, prepares row VMs with `canGenerate`
- [x] 9.4 Add `src/Controller/Admin/GenerateFormController.php` — `showAction` renders form with
      reference data (via `CachedReferenceClient`), `submitAction` validates → calls submitter →
      flash + redirect or re-render; plus `costAction` JSON endpoint for the live cost recalc
- [x] 9.5 Add `src/Controller/Admin/JobsHistoryController.php` — `indexAction` paginates from
      `PackshotJobRepository::listForGrid`, status filter via query string
- [x] 9.6 Register routes in `config/routes.yml`: `/qameraai/{products,generate,generate/submit,
      generate/cost,jobs}` (deviated from spec's `/modules/qameraai/*` to match the existing
      `/qameraai/configuration` pattern)
- [x] 9.7 Wire services in `config/services.yml` (CachedReferenceClientFactory + ReferenceCache +
      CalculatorBridge; controllers auto-discovered via `config/admin/services.yml`)

## 10. Twig templates + JS + CSS

- [x] 10.1 `views/templates/admin/products_grid.html.twig` — Bootstrap 4 table, bulk-select form,
      disabled action button + title hint for unsynced rows
- [x] 10.2 `views/templates/admin/generate_form.html.twig` — form with all fields, error-display
      partial, live cost display element
- [x] 10.3 `views/templates/admin/jobs_history.html.twig` — table with status badge, output thumbnail,
      error snippet, status filter dropdown
- [x] 10.4 `views/js/generate_form.js` — vanilla JS: cost recalc on field change (debounced fetch
      to the `/generate/cost` JSON endpoint)
- [x] 10.5 `views/css/admin.css` — badge palette per status
- [x] 10.6 No `package.json`, no `node_modules/`, no bundler config introduced

## 11. Admin tabs registration

- [x] 11.1 Extend `Installer::installAdminTabs()` to create parent `AdminQameraAi` under IMPROVE
      with children `AdminQameraAiProducts`, `AdminQameraAiJobs`, `AdminQameraAiConfiguration`
      (Configuration migrated from its prior standalone slot to be a child of the new parent)
- [x] 11.2 Extend `Installer::uninstallAdminTabs()` to remove children first, then parent
- [ ] 11.3 Tab labels in EN, PL, UK — currently EN-only; PL/UK come in Slice C i18n pass
- [ ] 11.4 Manual smoke — DEFERRED to Slice C runtime smoke

## 12. i18n strings

- [ ] 12.1 Add operator-visible strings to `translations/Modules.Qameraai.Admin.<lang>.xlf` for EN
      (fallback), PL (primary), UK
- [ ] 12.2 Verify no hardcoded strings in PHP/Twig via grep sweep:
      `grep -rE "'[A-Z][a-z]{3,}" src/Controller/Admin/ views/templates/admin/`

## 13. Integration test: full submit → webhook → row flips

- [ ] 13.1 PHPUnit integration test using `MockHandler` for outbound + invoking the webhook handler
      directly with a crafted payload:
  - seed one synced `ps_qamera_product_link` row
  - call submitter → assert 1 pending row in `ps_qamera_packshot_job`
  - dispatch a `job.completed` event via `EventDispatcher` with that `job_id`
  - assert row now `status=completed`, `output_url` populated
- [ ] 13.2 Same but for `job.failed` → `status=failed`, `last_error_message` populated
- [ ] 13.3 Pre-submit race test: dispatch `job.completed` BEFORE submitter persists, assert row created
      with the right FK

## 14. Quality gates green in worktree

- [x] 14.1 `vendor/bin/phpcs` clean on all new + touched files (PSR-12) [Slice A scope]
- [ ] 14.2 `vendor/bin/phpstan analyse --level=5` clean — DEFERRED to full Slice run; targeted
      analyse against the new files only fails locally on `Db` class resolution (env-specific,
      needs `_PS_ROOT_DIR_`; CI's neon config resolves it)
- [x] 14.3 `vendor/bin/phpunit` green on PHP 8.1 — 338 tests / 919 assertions, 12 integration tests
      skipped (pre-existing)
- [x] 14.4 PHP 8.2 / 8.3 docker matrix green (338/338 on both)

## 15. PrestaShop runtime smoke (main checkout, NOT worktree)

- [ ] 15.1 Exit worktree, `git checkout add-packshots-ui` in main checkout
- [ ] 15.2 `composer install` in main checkout
- [ ] 15.3 `make up` from `qameraai-prestashop/`; PS boots clean
- [ ] 15.4 `make install` — module installs, tabs appear, table created
- [ ] 15.5 BO → IMPROVE > Catalog > Qamera AI → Products: grid renders, synced row enabled, unsynced
      disabled with hint
- [ ] 15.6 Click Generate → form renders with reference dropdowns populated; pricing display shows
      a sensible number
- [ ] 15.7 Submit → flash success → redirected to Jobs history with row in `pending`
- [ ] 15.8 Wait for live webhook delivery (or manually trigger from Qamera panel) → row flips to
      `completed` with `output_url`
- [ ] 15.9 Fetch the output URL in a browser → image renders
- [ ] 15.10 `make uninstall` — module uninstalls cleanly, no orphan rows, tabs gone

## 16. CI + PR

- [ ] 16.1 Push branch, open PR; CI green on 8.1/8.2/8.3
- [ ] 16.2 Self-review against the spec scenarios — every scenario in every spec file has a
      corresponding test
- [ ] 16.3 Reviewer pass, fix any feedback, merge squash
- [ ] 16.4 `/opsx:archive add-packshots-ui` to sync deltas into main specs and move the change to
      `openspec/changes/archive/`
- [ ] 16.5 Tag a release as appropriate per repo convention
