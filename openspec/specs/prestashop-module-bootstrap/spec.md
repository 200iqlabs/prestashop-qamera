# prestashop-module-bootstrap Specification

## Purpose
TBD - created by archiving change bootstrap-prestashop-module. Update Purpose after archive.
## Requirements
### Requirement: Module installs and uninstalls cleanly on PrestaShop 8.x and 9.x

The module SHALL install with no warnings or errors on a stock PrestaShop 8.0+ or 9.x instance running PHP 8.1+, and SHALL uninstall to a clean state that leaves no module-owned tables, configuration keys, or admin tabs behind.

#### Scenario: Install on a stock PS 9.x

- **WHEN** an administrator uploads the module ZIP and clicks Install on a fresh PS 9.x install
- **THEN** `parent::install()` succeeds, `Installer::install()` returns `true`, the two `ps_qamera_*_link` tables exist, the five `QAMERAAI_*` configuration keys are seeded with their default values, the `AdminQameraAiConfiguration` tab is registered, and the four hooks are bound to the module

#### Scenario: Install on a stock PS 8.x

- **WHEN** the same flow runs on a fresh PS 8.x install
- **THEN** the install succeeds with the same observable state

#### Scenario: Re-install after a partial failure

- **WHEN** an administrator triggers Install on a module whose database state is partially present (e.g. one table was left over from a previous attempt)
- **THEN** the installer's `CREATE TABLE IF NOT EXISTS` statements succeed without errors, the configuration seeder uses `Configuration::get(...) === false` as the gate so existing values are preserved, and the install completes

#### Scenario: Uninstall removes module-owned state

- **WHEN** an administrator clicks Uninstall and confirms
- **THEN** the two link tables are dropped, the five `QAMERAAI_*` configuration keys are deleted, the admin tab is removed, and the module is fully detached

### Requirement: Configuration page persists module credentials and defaults

The back-office configuration page SHALL persist five settings via `Configuration::updateValue` — API base URL, API key, webhook secret, auto-register-products toggle, and sync batch size — and SHALL render their current values on each load. The page MUST be reachable from the module's `getContent` redirect and via the Symfony route `_qameraai_admin_configuration`.

#### Scenario: First-time configuration

- **WHEN** an administrator submits the form with non-empty API key and webhook secret values
- **THEN** the `QAMERAAI_API_KEY` and `QAMERAAI_WEBHOOK_SECRET` configuration keys are stored with the submitted values, the page reloads, and the secrets render in masked form

#### Scenario: API base URL persists across reloads

- **WHEN** the administrator changes the API base URL and saves
- **THEN** subsequent visits to the configuration page show the updated URL in the input field

#### Scenario: Submit without changing masked secrets

- **WHEN** the administrator submits the form without editing the masked-secret fields
- **THEN** the previously saved secrets remain unchanged in `Configuration` storage

#### Scenario: Auto-register toggle persists boolean

- **WHEN** the administrator toggles auto-register-products and submits
- **THEN** `QAMERAAI_AUTO_REGISTER_PRODUCTS` stores `'1'` or `'0'` matching the checkbox state

### Requirement: Secrets never leave the server in cleartext on render

The configuration page MUST render every secret-bearing field (`api_key`, `webhook_secret`) with all but the last four characters replaced by the bullet character `•` repeated 12 times. The submit handler MUST skip persisting any field whose posted value still starts with the masking prefix, treating it as a no-op rather than overwriting the saved secret with the masked string.

#### Scenario: Render with a saved secret

- **WHEN** the configuration page renders with a stored API key
- **THEN** the value attribute on the `api_key` input contains exactly twelve bullets followed by the last four characters of the stored value

#### Scenario: Skip-on-mask submit

- **WHEN** the administrator submits the form with the API key field still containing the masked placeholder
- **THEN** the controller leaves `QAMERAAI_API_KEY` unchanged

### Requirement: DB schema commits the natural-key columns up front

The installer SHALL create `ps_qamera_product_link` and `ps_qamera_packshot_link` with the full column set the integration uses: a UUID column linking to the Qamera AI side (`qamera_product_id`, `qamera_packshot_id`), a `VARCHAR(200)` natural key (`qamera_*_ref`) with a unique index, a foreign-key column to PrestaShop's product table (`id_product`), and timestamp columns. The packshot table additionally carries `qamera_job_id` and a `status ENUM('pending','ready','archived')`. No subsequent phase may introduce a breaking schema change to these column shapes without a versioned `upgrade/upgrade-X.Y.Z.php` script.

#### Scenario: Tables exist after install

- **WHEN** the install completes
- **THEN** `INFORMATION_SCHEMA.TABLES` lists `<prefix>qamera_product_link` and `<prefix>qamera_packshot_link`, both with `ENGINE=InnoDB` and `CHARSET=utf8mb4`

#### Scenario: Unique index on natural key

- **WHEN** the installer creates the product-link table
- **THEN** `INFORMATION_SCHEMA.STATISTICS` shows a `UNIQUE` index on `qamera_product_ref`

### Requirement: i18n strings available for EN, PL, UK

Every user-visible string on the configuration page SHALL be translatable through the `Modules.Qameraai.Admin` XLIFF domain, with translations provided in English, Polish, and Ukrainian.

#### Scenario: Polish locale renders Polish labels

- **WHEN** the back-office locale is `pl-PL`
- **THEN** the configuration page renders the Polish translation for every label

#### Scenario: Untranslated locale falls back to English

- **WHEN** the back-office locale is one for which no XLIFF translation file exists
- **THEN** the page renders the English source values instead of the raw translation key

### Requirement: CI passes from day 1 on PHP 8.1 / 8.2 / 8.3

The GitHub Actions workflow SHALL run PHPCS (PSR-12), PHPStan (level 5), and PHPUnit against PHP 8.1, 8.2, and 8.3 on every push to `main` and every pull request. All three jobs MUST pass on the bootstrap commit.

#### Scenario: PR opens, all checks green

- **WHEN** a pull request is opened against `main` with the bootstrap commit
- **THEN** the `static-analysis (PHP 8.1)`, `(PHP 8.2)`, and `(PHP 8.3)` jobs all report success

