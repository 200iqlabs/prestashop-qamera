# prestashop-module-bootstrap Specification (delta)

## MODIFIED Requirements

### Requirement: DB schema commits the natural-key columns up front

The installer SHALL create `ps_qamera_product_link` and `ps_qamera_packshot_link` with the full column set the integration uses: a UUID column linking to the Qamera AI side (`qamera_product_id`, `qamera_packshot_id`), a `VARCHAR(200)` natural key (`qamera_*_ref`) with a unique index, a foreign-key column to PrestaShop's product table (`id_product`), and timestamp columns. The packshot table additionally carries `qamera_job_id CHAR(36) NULL`, `id_shop INT UNSIGNED NOT NULL`, `status ENUM('pending','ready','failed','cancelled','archived') NOT NULL DEFAULT 'pending'`, `last_synced_at DATETIME NULL`, `last_error_message TEXT NULL`, `updated_at DATETIME NOT NULL`, and a `UNIQUE` index on `qamera_packshot_id` so the webhook-event-dispatch upsert serialises at the database layer. No subsequent phase may introduce a breaking schema change to these column shapes without a versioned `upgrade/upgrade-X.Y.Z.php` script.

On upgrade from a pre-4.2 install (where `ps_qamera_packshot_link` was created with the narrower status ENUM and without `last_synced_at` / `last_error_message` / `updated_at` columns), `Installer::createSchema()` SHALL probe `INFORMATION_SCHEMA.COLUMNS` and emit additive `ALTER TABLE … ADD COLUMN` / `MODIFY COLUMN status ENUM(…)` statements only for columns or ENUM widenings that are missing. The migration MUST be idempotent — repeated install or upgrade calls SHALL succeed without errors and SHALL NOT mutate rows whose columns already match the target definitions.

#### Scenario: Tables exist after install
- **WHEN** the install completes
- **THEN** `INFORMATION_SCHEMA.TABLES` lists `<prefix>qamera_product_link` and `<prefix>qamera_packshot_link`, both with `ENGINE=InnoDB` and `CHARSET=utf8mb4`

#### Scenario: Unique index on natural key
- **WHEN** the installer creates the product-link table
- **THEN** `INFORMATION_SCHEMA.STATISTICS` shows a `UNIQUE` index on `qamera_product_ref`

#### Scenario: Unique index on qamera_packshot_id supports upsert
- **WHEN** the installer creates the packshot-link table
- **THEN** `INFORMATION_SCHEMA.STATISTICS` shows a `UNIQUE` index on `qamera_packshot_id`

#### Scenario: Packshot status ENUM accepts the four lifecycle states
- **WHEN** the install (or idempotent upgrade) completes
- **THEN** the `status` column on `<prefix>qamera_packshot_link` SHALL be `ENUM('pending','ready','failed','cancelled','archived') NOT NULL DEFAULT 'pending'`

#### Scenario: Packshot bookkeeping columns are present
- **WHEN** the install (or idempotent upgrade) completes
- **THEN** `<prefix>qamera_packshot_link` SHALL carry `last_synced_at DATETIME NULL`, `last_error_message TEXT NULL`, and `updated_at DATETIME NOT NULL`

#### Scenario: Upgrade from pre-4.2 install adds missing columns without dropping rows
- **GIVEN** an existing PS install where `<prefix>qamera_packshot_link` was created by an earlier module version with `status ENUM('pending','ready','archived')` and without `last_synced_at` / `last_error_message` / `updated_at`
- **WHEN** the 4.2 installer runs `createSchema()`
- **THEN** `ALTER TABLE` statements SHALL add the missing columns (nullable / defaulted) and widen the `status` ENUM additively
- **AND** any pre-existing rows SHALL survive with their previous `status` value intact and the new columns populated as `NULL` / default
- **AND** re-running `createSchema()` immediately after SHALL be a no-op (no further `ALTER` emitted)
