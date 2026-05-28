## 1. Preflight + branch setup

- [ ] 1.1 Verify `SubmitJobRequest` DTO already reflects the session-envelope shape (`session_config` +
      `subjects[]`) — `grep -n session_config src/Api/Dto/SubmitJobRequest.php`
- [ ] 1.2 Verify upstream `add-plugin-session-lifecycle` change is deployed to the live env used for smoke
      (operator confirms; no automated check)
- [ ] 1.3 Create branch `add-packshots-ui` off `main`, push so worktree workflow can pick it up
- [ ] 1.4 Set up `.claude/worktrees/add-packshots-ui` per CLAUDE.md "Git worktrees" protocol; run
      `composer install` inside via the documented one-shot docker command

## 2. API client: listMannequinModels + DTO

- [ ] 2.1 Add `src/Api/Dto/MannequinModel.php` mirroring `Scenery` shape (`id`, `name`, `thumbnail`,
      `source`, `status`, `createdAt`)
- [ ] 2.2 Add `QameraApiClient::listMannequinModels(): array` — wrapper key `models`, same retry/header
      pattern as `listSceneries()`
- [ ] 2.3 Add unit tests via `MockHandler`: happy path, wrong wrapper key surfaces `ValidationException`,
      empty list returns `[]`
- [ ] 2.4 Update contract test fixtures snapshot under `tests/Fixtures/` for `/models`

## 3. Reference cache decorator

- [ ] 3.1 Add `src/Api/Cache/ReferenceCache.php` — small wrapper around `\Cache::getInstance()` with
      filesystem fallback under `_PS_CACHE_DIR_ . 'qameraai/reference/'`, key shape
      `qameraai:ref:<endpoint>:<sha256(api_key)[0:16]>`
- [ ] 3.2 Add `src/Api/Cache/CachedReferenceClient.php` decorator implementing the six reference methods
      with the TTL table from `qamera-api-client` delta spec
- [ ] 3.3 Wire `CachedReferenceClient` into `config/services.yml` — injected with the underlying
      `QameraApiClient` + the configured API key (read from `Configuration::get('QAMERAAI_API_KEY')`)
- [ ] 3.4 Unit tests: cache hit within TTL, miss after TTL expiry, different API keys do not share entries
- [ ] 3.5 Filesystem cleanup helper for tests (`tearDown` deletes `_PS_CACHE_DIR_ . 'qameraai/reference/'`)

## 4. ps_qamera_packshot_job table + installer

- [ ] 4.1 Add `install()` DDL in `src/Install/Installer.php`: `ps_qamera_packshot_job` per the
      `packshot-jobs` spec (PK, UNIQUE on `qamera_job_id` and `packshot_external_ref`, FK CASCADE to
      `ps_qamera_product_link`, indexes on `(id_shop,id_product)` and `(status,submitted_at)`)
- [ ] 4.2 Add `uninstall()` DROP for the same table, run BEFORE dropping `ps_qamera_product_link` so the
      FK doesn't block
- [ ] 4.3 Ensure idempotent install via `CREATE TABLE IF NOT EXISTS` (matches existing pattern)
- [ ] 4.4 Manual smoke from main checkout: `make install` → `SHOW CREATE TABLE ps_qamera_packshot_job\G`
      to confirm DDL matches spec
- [ ] 4.5 Manual smoke: `make uninstall` → confirm table gone

## 5. PackshotJobRepository

- [ ] 5.1 Add `src/Packshot/PackshotJobRow.php` value object (immutable, all spec columns)
- [ ] 5.2 Add `src/Packshot/PackshotJobRepository.php` with `findByJobId`, `findByExternalRef`,
      `listForGrid(JobsGridFilters)`, `insertBatch(array $rows): void`,
      `upsertFromWebhook(PackshotJobWebhookUpdate)`
- [ ] 5.3 `insertBatch` uses single multi-row `INSERT … ON DUPLICATE KEY UPDATE` on `qamera_job_id`
- [ ] 5.4 `listForGrid` joins `ps_product_lang` for localised product name, respects `id_lang`
- [ ] 5.5 All queries use prepared statements via `Db::getInstance()->execute()` — PHPCS sniff for raw
      concatenation
- [ ] 5.6 Unit tests using PS test DB (or in-memory SQLite fallback if PS DB not available): insert →
      find → list-filter-by-status, idempotency of `insertBatch`

## 6. PackshotJobSubmitter

- [ ] 6.1 Add `src/Packshot/SubmitFormInput.php` value object collecting the form fields
- [ ] 6.2 Add `src/Packshot/SubmitResult.php` (sessions_submitted, sessions_failed, jobs_persisted,
      per-chunk failures with reasons)
- [ ] 6.3 Add `src/Packshot/PackshotJobSubmitter.php` — `submit(SubmitFormInput): SubmitResult`
  - generates UUID v4 per subject for `packshot_external_ref` (`Symfony\Component\Uid\Uuid::v4()` if
    available, else `bin2hex(random_bytes(16))` formatted)
  - chunks subjects into ≤100 per session
  - per chunk: builds `SubmitJobRequest` with `auto_register_packshot=true`, calls
    `QameraApiClient::submitJob()` with a fresh `Idempotency-Key`
  - on success: maps response → rows, calls `PackshotJobRepository::insertBatch`
  - on failure: aggregates chunk-level error info, no DB writes for failed chunk
- [ ] 6.4 Unit tests via `MockHandler`: single subject, bulk 5×2, 503 leaves DB unchanged, 422 propagates
      `ApiValidationException`, 247-subject chunking emits 3 calls with distinct keys, partial chunk
      failure reports correctly
- [ ] 6.5 Test confirming `auto_register_packshot=true` and ref format `^ps:\d+:\d+:packshot:[0-9a-f-]{36}$`

## 7. CostCalculator

- [ ] 7.1 Add `src/Packshot/CostCalculator.php` — `estimate(string $aiModel, int $imagesCount, int
      $subjectCount): ?int` reading from cached `Pricing` (via `CachedReferenceClient::getPricing()`)
- [ ] 7.2 Unit tests: known model → product of three numbers; unknown model → `null`

## 8. PackshotJobUpdater + webhook handler wiring

- [ ] 8.1 Add `src/Packshot/PackshotJobUpdater.php` — `upsert(PackshotJobWebhookUpdate $update): void`
      with status mapping per `webhook-handler` delta spec
- [ ] 8.2 Add `src/Packshot/PackshotJobWebhookUpdate.php` value object
- [ ] 8.3 Extend `JobCompletedHandler` to inject `PackshotJobUpdater` and call `upsert(...)` with the
      mapped fields (existing `PackshotLinkUpdater` + `ProductLinkHeartbeat` paths unchanged)
- [ ] 8.4 Same extension in `JobFailedHandler` (`last_error_message`), `JobRetriedHandler`
      (`in_progress`), `JobCancelledHandler` (`cancelled`)
- [ ] 8.5 Pre-submit race: if row absent, insert using parsed `external_ref` → product link lookup
- [ ] 8.6 Unknown payload status → map to `pending`, log WARNING with the unknown value, do not throw
- [ ] 8.7 Wire all four handlers + updater in `config/services.yml`
- [ ] 8.8 Unit tests per handler: existing-row upsert, pre-submit insert path, unknown-status warning
      path, no FK link → log warning, ACK 200 either way

## 9. BO controllers + routes

- [ ] 9.1 Add `controllers/admin/AdminQameraAiProductsController.php` thin shim → forwards to
      Symfony `ProductsGridController`
- [ ] 9.2 Add `controllers/admin/AdminQameraAiJobsController.php` shim → `JobsHistoryController`
- [ ] 9.3 Add `src/Controller/Admin/ProductsGridController.php` extending
      `FrameworkBundleAdminController` — `indexAction(Request)` paginates link rows, joins
      `ps_product_lang`, prepares row VMs with `canGenerate = qamera_image_id !== null`
- [ ] 9.4 Add `src/Controller/Admin/GenerateFormController.php` — `showAction` renders form with
      reference data (via `CachedReferenceClient`), `submitAction` validates → calls submitter →
      flash + redirect or re-render
- [ ] 9.5 Add `src/Controller/Admin/JobsHistoryController.php` — `indexAction` paginates from
      `PackshotJobRepository::listForGrid`, status filter via query string
- [ ] 9.6 Register routes in `config/routes.yml`: `/modules/qameraai/{products,generate,jobs}`
- [ ] 9.7 Wire services in `config/services.yml` (controllers, submitter, calculator, repository,
      updater, cached reference client)

## 10. Twig templates + JS + CSS

- [ ] 10.1 `views/templates/admin/products_grid.html.twig` — Bootstrap 4 table, bulk-select form,
      disabled action button + title hint for unsynced rows
- [ ] 10.2 `views/templates/admin/generate_form.html.twig` — form with all fields, error-display
      partial, cost display element
- [ ] 10.3 `views/templates/admin/jobs_history.html.twig` — table with status badge, output thumbnail,
      error snippet, status filter dropdown
- [ ] 10.4 `views/js/generate_form.js` — vanilla JS: subject toggle, cost recalc on field change
      (calls a small JSON endpoint or recomputes from pre-rendered pricing map), max-subjects guard
- [ ] 10.5 `views/css/admin.css` — minimal additions (badges, disabled state)
- [ ] 10.6 Ensure NO `package.json`, NO `node_modules/`, NO bundler config introduced

## 11. Admin tabs registration

- [ ] 11.1 Extend `Installer::installTabs()` (or add it) to create parent `AdminQameraAi` under IMPROVE
      > Catalog, plus child tabs `AdminQameraAiProducts`, `AdminQameraAiJobs`
- [ ] 11.2 Extend `Installer::uninstallTabs()` to remove all three in reverse order
- [ ] 11.3 Tab labels in EN, PL, UK
- [ ] 11.4 Manual smoke: parent + children appear under IMPROVE > Catalog after `make install`

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

- [ ] 14.1 `vendor/bin/phpcs` clean (PSR-12)
- [ ] 14.2 `vendor/bin/phpstan analyse --level=5` clean (worktree-local, excluding `src/Install/*`)
- [ ] 14.3 `vendor/bin/phpunit` green
- [ ] 14.4 Run the same three under PHP 8.2 and 8.3 dockers to mirror CI matrix

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
