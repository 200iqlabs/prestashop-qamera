## MODIFIED Requirements

### Requirement: Module installs and uninstalls cleanly on PrestaShop 8.x and 9.x

The module SHALL install with no warnings or errors on a stock PrestaShop 8.0+ or 9.x instance running PHP 8.1+, and SHALL uninstall to a clean state that leaves no module-owned tables, configuration keys, or admin tabs behind.

The `qamera_product_link` table SHALL be created (or migrated, on upgrade from earlier installs) with the columns required for lazy product-sync bookkeeping: `qamera_product_id CHAR(36) NULL` (filled only after upstream registration succeeds), plus `display_name_snapshot VARCHAR(500) NOT NULL`, `sku_snapshot VARCHAR(100) NULL`, `description_snapshot TEXT NULL`, `status ENUM('pending','registered','error') NOT NULL DEFAULT 'pending'`, `last_error_message TEXT NULL`, and `last_synced_at DATETIME NULL`. The migration MUST be idempotent (`ADD COLUMN IF NOT EXISTS`, `MODIFY COLUMN` with the same target definition) so repeated install or upgrade calls succeed without errors.

#### Scenario: Install on a stock PS 9.x

- **WHEN** an administrator uploads the module ZIP and clicks Install on a fresh PS 9.x install
- **THEN** `parent::install()` succeeds, `Installer::install()` returns `true`, the two `ps_qamera_*_link` tables exist with the full Phase-2 column set on `qamera_product_link`, the five `QAMERAAI_*` configuration keys are seeded with their default values, the `AdminQameraAiConfiguration` tab is registered, and the four hooks are bound to the module

#### Scenario: Install on a stock PS 8.x

- **WHEN** the same flow runs on a fresh PS 8.x install
- **THEN** the install succeeds with the same observable state

#### Scenario: Re-install after a partial failure

- **WHEN** an administrator triggers Install on a module whose database state is partially present (e.g. one table was left over from a previous attempt)
- **THEN** the installer's `CREATE TABLE IF NOT EXISTS` statements succeed without errors, the configuration seeder uses `Configuration::get(...) === false` as the gate so existing values are preserved, and the install completes

#### Scenario: Upgrade from Phase-1 schema

- **WHEN** the module is installed on a PS instance that already has a `ps_qamera_product_link` table from the Phase-1 install (where `qamera_product_id` was `NOT NULL` and the snapshot/status columns were absent)
- **THEN** `Installer::createSchema` runs `ALTER TABLE` statements that relax `qamera_product_id` to nullable and add the missing snapshot/status columns, and any pre-existing rows survive with `status='pending'` (default) and empty snapshot columns

#### Scenario: Uninstall removes module-owned state

- **WHEN** an administrator clicks Uninstall and confirms
- **THEN** the two link tables are dropped, the five `QAMERAAI_*` configuration keys are deleted, the admin tab is removed, and the module is fully detached
