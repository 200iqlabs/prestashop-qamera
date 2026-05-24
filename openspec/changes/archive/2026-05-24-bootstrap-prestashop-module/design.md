## Context

This is a greenfield repository. The integration target is the Qamera AI Plugin API (REST + outbound webhooks under `/api/v1/plugin/*`). PrestaShop is a Symfony-backed PHP application that ships its own module convention: a class extending `Module`, an `install()` lifecycle hook, an autoloaded `src/` directory, XLIFF translations, and Twig templates rendered through controllers registered in `config/routes.yml`.

The bootstrap layer must be installable on a stock PS 8.x and PS 9.x instance without needing service code that has not been written yet. Phase 2 will wire the actual HTTP calls against Qamera AI; until then, the configuration page is a credentials editor and nothing more.

## Goals / Non-Goals

**Goals**

- A `composer install` followed by uploading the module ZIP installs cleanly on PS 8.x and PS 9.x.
- The back-office configuration screen renders, persists, and re-renders saved values with masked secrets.
- The `Installer` is idempotent: re-installing after a partial failure leaves a consistent state.
- The DB schema is forward-compatible with the entities Phase 2 + Phase 3 will use (`ps_qamera_product_link`, `ps_qamera_packshot_link` with `qamera_*_id` UUID columns and unique `qamera_*_ref` natural keys).
- CI is green from day 1 on PSR-12, PHPStan level 5, and a single smoke PHPUnit test.

**Non-Goals**

- No service implementations (`QameraApiClient`, `ProductSyncService`, `WebhookHandlerService`) — Phase 2.
- No outgoing HTTP, no webhook front controller, no hook handler bodies.
- No CLI commands — Phase 4.
- No multistore-aware credential storage. Configuration is global; multistore lands when there is a real user with that need.

## Decisions

### D1. Two-namespace install path: `Installer` does the work, `qameraai.php` only delegates

**Decision.** `qameraai.php` (the PS-required entry class) contains constructor metadata and three thin overrides — `install()`, `uninstall()`, `getContent()`. Real work lives in `QameraAi\Module\Install\Installer`.

**Why.** PrestaShop loads the entry file before Composer autoload is guaranteed; keeping that file thin minimises the risk of class-load failures during the install step. The bulk of the logic is autoloaded normally via PSR-4 once the parent `install()` succeeds.

**Alternative considered.** Single fat `qameraai.php`. Rejected — slows iteration, harder to unit-test, and conflicts with PS's own conventions for community modules of any size.

### D2. Configuration persistence via PS `Configuration::updateValue` keyed `QAMERAAI_*`

**Decision.** Five `Configuration` keys: `QAMERAAI_API_BASE_URL`, `QAMERAAI_API_KEY`, `QAMERAAI_WEBHOOK_SECRET`, `QAMERAAI_AUTO_REGISTER_PRODUCTS`, `QAMERAAI_SYNC_BATCH_SIZE`. Defaults are seeded by the installer; secrets default to empty string. All five are purged on uninstall.

**Why.** This is the canonical PS storage for module-wide configuration. It survives module disable / re-enable cycles and works without any custom schema for KV.

**Alternative considered.** Dedicated `ps_qamera_config` table. Rejected as overkill until per-shop credentials become a real requirement (deferred to v2 per resolved decision OQ-PS1).

### D3. Secrets masked on render, accepted only when changed

**Decision.** The configuration controller renders `••••••••••••<last 4 chars>` for the API key and webhook secret. The submit handler skips overwriting a field whose posted value still starts with the masking dot prefix. Cleartext secrets exist only momentarily on POST and are never serialised back to the browser.

**Why.** Two threats — accidental leakage in browser DevTools / proxy logs, and accidental overwrite on partial-submission. Both are handled by the mask-on-render + skip-if-unchanged pair.

**Alternative considered.** Password-type inputs without masking. Rejected — `<input type="password">` still leaks the cleartext through the value attribute when the browser re-fills it on form re-render after a validation error. Server-side masking is more robust.

### D4. Schema commits the natural-key columns now even though Phase 2 fills them

**Decision.** `ps_qamera_product_link` and `ps_qamera_packshot_link` ship with the full Phase 2/3 column set (`qamera_*_id` UUID, `qamera_*_ref`, `qamera_job_id`, timestamps). The tables are empty until Phase 2 starts inserting.

**Why.** Schema migrations on a PS module mid-flight require version bumps and migration files in `upgrade/`. Settling the schema up-front avoids that overhead during fast iteration. The columns are well-defined in the upstream design doc and won't change shape.

**Alternative considered.** Drip-feed the schema per phase. Rejected — every shift forces a `upgrade/upgrade-X.Y.Z.php` file and a version bump, which is friction for negligible benefit when the target table shape is already known.

### D5. CI runs PSR-12 + PHPStan level 5 + PHPUnit with a single smoke test

**Decision.** GitHub Actions matrix on PHP 8.1 / 8.2 / 8.3. The PHPUnit run has exactly one passing test (`SmokeTest::testAutoloadResolves`) so the green badge means something concrete from day 1 — autoload works in CI under all three PHP versions.

**Why.** Catches PHP-version-specific autoload / class-load issues before the first real test gets written. Phase 2 fills the suite.

### D6. Docker Compose files mount the host repo into the module directory

**Decision.** Both `docker-compose.ps9.yml` and `docker-compose.ps8.yml` declare `../:/var/www/html/modules/qameraai:rw` — the host repo is bind-mounted into the container's modules dir so code edits show up after a refresh.

**Why.** Hot-reload-equivalent for PHP. Avoids the rebuild-image cycle every developer would otherwise hit.

**Alternative considered.** Docker image baked with the module pre-installed. Rejected for dev — image rebuilds on every edit defeat the point.

## Risks / Trade-offs

- **[Risk] PrestaShop bumps Composer autoloader path between 8.x and 9.x.** → **Mitigation:** the entry file checks `file_exists($autoload)` before requiring; if the host PS ships an alternative path we bail gracefully instead of fatal-erroring. Phase 2 should add a smoke test that the `QameraAi` class loads under both Compose profiles.
- **[Risk] `Tab::add()` returns `false` silently if the parent ID is wrong.** → **Mitigation:** `id_parent = -1` makes the tab hidden, satisfying PS's invariant without requiring a parent tab. The configuration screen is reached through `getContent()` redirect, not the menu, so an invisible tab is acceptable.
- **[Risk] Webhook secret leaks via flash messages on validation error.** → **Mitigation:** secrets are only persisted, never echoed back into the form value attribute after submit. Flash messages contain a generic "Settings saved." string, no field values.
- **[Trade-off] PHPStan level 5 vs level 8.** Level 5 is conservative for a greenfield repo touching PS globals (`Db`, `Configuration`, `Tab`, `Module`). Level 8 would force a stubs file from the start; we defer that until Phase 2 when the static surface grows.

## Migration Plan

- **Install:** `composer install --no-dev`, zip, upload via PS Module Manager, click Install. The installer is idempotent — re-running it leaves a consistent DB / `Configuration` state.
- **Uninstall:** confirms with the user, drops the two tables, purges the five `Configuration` keys, removes the hidden admin tab. Already-uploaded assets on Qamera AI side are intentionally not touched (the merchant retains them).
- **Upgrade story:** Phase 2 schema additions go through `upgrade/upgrade-1.1.0.php`. Phase 1 ships at `1.0.0`.

## Open Questions

- Should the configuration page surface a read-only diagnostic block (account name, plan, credits balance) once `Test Connection` ships in Phase 2? Likely yes — would consume the existing `/api/v1/plugin/me` endpoint. Tracked as a Phase 2 task.
