## ADDED Requirements

### Requirement: Installer creates and drops ps_qamera_packshot_job table

`Install/Installer.php` SHALL create the `ps_qamera_packshot_job` table on `install()` and DROP it on
`uninstall()`. The DDL SHALL satisfy the schema defined by the `packshot-jobs` capability (PK, UNIQUE on
`qamera_job_id`, UNIQUE on `packshot_external_ref`, FK to `ps_qamera_product_link.id_qamera_product_link`
with `ON DELETE CASCADE`, indexes on `(id_shop, id_product)` and `(status, submitted_at)`).

The install SHALL be idempotent — running `install()` twice MUST NOT error. The uninstall SHALL drop the
table even if it contains rows; operator confirmation belongs to the PS uninstall UI, not this layer.

#### Scenario: Fresh install creates the table

- **GIVEN** the database has no `ps_qamera_packshot_job` table
- **WHEN** `Installer::install()` runs
- **THEN** the table exists with all required columns, indexes, and the FK to `ps_qamera_product_link`

#### Scenario: Repeated install is idempotent

- **GIVEN** the table already exists
- **WHEN** `Installer::install()` runs again
- **THEN** no error is raised and the table schema is unchanged

#### Scenario: Uninstall drops the table

- **WHEN** `Installer::uninstall()` runs
- **THEN** the `ps_qamera_packshot_job` table no longer exists

### Requirement: Installer registers BO admin tabs for the Qamera AI section

`Install/Installer.php` SHALL register a parent admin tab `AdminQameraAi` under `IMPROVE > Catalog`,
with two child tabs `AdminQameraAiProducts` (route → `ProductsGridController::indexAction`) and
`AdminQameraAiJobs` (route → `JobsHistoryController::indexAction`). Tab names SHALL be translatable in
EN, PL, and UK.

Uninstall SHALL remove all three tabs in reverse order (children before parent).

#### Scenario: Install creates parent + two child tabs

- **WHEN** `Installer::install()` runs
- **THEN** `ps_tab` contains rows for `AdminQameraAi`, `AdminQameraAiProducts`, `AdminQameraAiJobs`
- **AND** `AdminQameraAiProducts.id_parent` and `AdminQameraAiJobs.id_parent` reference the parent tab

#### Scenario: Uninstall removes tabs in reverse order

- **WHEN** `Installer::uninstall()` runs
- **THEN** the three tabs are deleted from `ps_tab` (children first, then parent)
- **AND** no orphan rows remain
