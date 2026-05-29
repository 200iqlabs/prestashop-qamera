<?php

declare(strict_types=1);

namespace QameraAi\Module\Install;

use Db;
use Module;
use Tab;
use Language;
use Configuration;

/**
 * Encapsulates everything the module needs to set up when PrestaShop runs
 * `parent::install()` — DB tables, configuration defaults, hook
 * registration, and back-office admin tabs. The uninstall path mirrors
 * each step so the module can be cleanly removed.
 *
 * Phase 1: scaffolding only. The schema and hooks are committed early so
 * subsequent phases can add services and controllers without revisiting
 * the install lifecycle.
 */
final class Installer
{
    private const HOOKS = [
        // PrestaShop 8/9 fire `actionProductSave` on both Product::add()
        // and Product::update(); `actionProductAdd` is dispatched only
        // by the BO ProductDuplicator. We register Save (covers new
        // product creation) plus Update (covers BO edits) and Add
        // (covers duplications). The writer's upsert is idempotent, so
        // a Save+Update double-fire during edits is harmless.
        'actionProductSave',
        'actionProductAdd',
        'actionProductUpdate',
        // PS 8/9 fires `actionWatermark` after image upload for a product
        // (PS 9 dropped `actionProductImage`). Phase 3 uses it as the
        // trigger for upstream image-sync; the hook handler delegates to
        // `ProductImageSyncService::syncOnImageAdded`.
        'actionWatermark',
        'displayAdminProductsExtra',
        'displayBackOfficeHeader',
    ];

    private const DEFAULTS = [
        'QAMERAAI_API_BASE_URL' => 'https://qamera.ai/api/v1/plugin',
        'QAMERAAI_API_KEY' => '',
        'QAMERAAI_WEBHOOK_SECRET' => '',
        'QAMERAAI_AUTO_REGISTER_PRODUCTS' => '0',
        'QAMERAAI_SYNC_BATCH_SIZE' => '100',
    ];

    public function __construct(private readonly Module $module)
    {
    }

    public function install(): bool
    {
        return $this->createSchema()
            && $this->registerHooks()
            && $this->seedDefaults()
            && $this->installAdminTabs();
    }

    public function uninstall(): bool
    {
        return $this->uninstallAdminTabs()
            && $this->dropSchema()
            && $this->purgeConfiguration();
    }

    private function createSchema(): bool
    {
        $prefix = _DB_PREFIX_;
        $charset = 'utf8mb4';

        $statements = [
            "CREATE TABLE IF NOT EXISTS `{$prefix}qamera_product_link` (
                `id_link` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_product` INT UNSIGNED NOT NULL,
                `id_shop` INT UNSIGNED NOT NULL,
                `qamera_product_id` CHAR(36) NULL,
                `qamera_product_ref` VARCHAR(200) NOT NULL,
                `display_name_snapshot` VARCHAR(500) NOT NULL,
                `sku_snapshot` VARCHAR(100) NULL,
                `description_snapshot` TEXT NULL,
                `status` ENUM('pending','registered','error') NOT NULL DEFAULT 'pending',
                `last_error_message` TEXT NULL,
                `last_synced_at` DATETIME NULL,
                `analysis_status`
                    ENUM('pending','processing','described','error','partial')
                    NULL DEFAULT NULL,
                `analysis_described_count` INT UNSIGNED NULL DEFAULT NULL,
                `analysis_total_count` INT UNSIGNED NULL DEFAULT NULL,
                `analysis_refreshed_at` DATETIME NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL,
                PRIMARY KEY (`id_link`),
                UNIQUE KEY `qamera_product_link_psprod` (`id_product`, `id_shop`),
                UNIQUE KEY `qamera_product_link_ref` (`qamera_product_ref`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset};",

            "CREATE TABLE IF NOT EXISTS `{$prefix}qamera_packshot_link` (
                `id_link` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_product` INT UNSIGNED NOT NULL,
                `id_shop` INT UNSIGNED NOT NULL,
                `qamera_packshot_id` CHAR(36) NOT NULL,
                `qamera_packshot_ref` VARCHAR(200) NOT NULL,
                `qamera_job_id` CHAR(36) NULL,
                `status` ENUM('pending','ready','failed','cancelled','archived') NOT NULL DEFAULT 'pending',
                `last_error_message` TEXT NULL,
                `last_synced_at` DATETIME NULL,
                `created_at` DATETIME NOT NULL,
                -- DEFAULT CURRENT_TIMESTAMP mirrors what migratePackshotLinkSchema()
                -- emits on the ALTER path, so fresh installs and upgraded
                -- installs share an identical schema (no INSERT-without-
                -- updated_at divergence between the two install paths).
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id_link`),
                UNIQUE KEY `qamera_packshot_link_ref` (`qamera_packshot_ref`),
                UNIQUE KEY `qamera_packshot_link_qamera_packshot_id` (`qamera_packshot_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset};",

            // Phase 4.1 — inbound webhook delivery log. PK is the upstream
            // `X-Qamera-Delivery-Id` so concurrent duplicate inserts serialise
            // on the index instead of needing application-level locking.
            "CREATE TABLE IF NOT EXISTS `{$prefix}qamera_webhook_delivery` (
                `delivery_id` VARCHAR(64) NOT NULL,
                `received_at` DATETIME NOT NULL,
                `event_type` VARCHAR(64) NOT NULL,
                `status` ENUM('accepted','duplicate','rejected') NOT NULL,
                `last_error_message` TEXT NULL,
                `raw_payload` MEDIUMTEXT NOT NULL,
                PRIMARY KEY (`delivery_id`),
                KEY `qamera_webhook_event_type` (`event_type`, `received_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset};",

            // Phase 4.3 — local mirror of submitted generation jobs.
            // One row per upstream `job_id` returned by `POST /jobs`. Rows
            // live independently of `qamera_packshot_link` so failed jobs
            // (no packshot row) and pending jobs (submitted, no webhook
            // yet) remain visible to the operator. FK targets
            // `qamera_product_link.id_link` (the table's real PK column —
            // the OpenSpec uses the logical name `id_qamera_product_link`
            // for clarity, but the physical key is `id_link`). CASCADE
            // on delete so unsyncing a product cleans up its job history.
            "CREATE TABLE IF NOT EXISTS `{$prefix}qamera_packshot_job` (
                `id_qamera_packshot_job` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `qamera_job_id` CHAR(36) NOT NULL,
                `qamera_order_id` CHAR(36) NOT NULL,
                `id_qamera_product_link` INT UNSIGNED NOT NULL,
                `id_shop` INT UNSIGNED NOT NULL,
                `id_product` INT UNSIGNED NOT NULL,
                `packshot_external_ref` VARCHAR(100) NOT NULL,
                `status` ENUM('pending','in_progress','completed','failed','cancelled')
                    NOT NULL DEFAULT 'pending',
                `output_url` TEXT NULL,
                `output_url_expires_at` DATETIME NULL,
                `last_error_message` TEXT NULL,
                `ai_model` VARCHAR(100) NOT NULL,
                `aspect_ratio` VARCHAR(8) NOT NULL,
                `images_count` SMALLINT UNSIGNED NOT NULL,
                -- JSON column type: native on MySQL 5.7+ / MariaDB 10.2+,
                -- which is the floor PrestaShop 8/9 already require.
                `session_config_json` JSON NOT NULL,
                `submitted_at` DATETIME NOT NULL,
                `last_synced_at` DATETIME NULL,
                PRIMARY KEY (`id_qamera_packshot_job`),
                UNIQUE KEY `qamera_packshot_job_job_id` (`qamera_job_id`),
                -- packshot_external_ref is NOT unique on purpose: with
                -- `images_count > 1` a single submitted Subject yields N
                -- `job_ids` upstream that all map to the SAME local
                -- packshot row (`auto_register_packshot=true` registers
                -- one packshot per Subject regardless of imagesCount).
                -- The OpenSpec preview marked this UNIQUE; that conflicts
                -- with its own 10-rows-for-5-products-x-2-images scenario.
                -- Surface the divergence in PR review.
                KEY `qamera_packshot_job_external_ref` (`packshot_external_ref`),
                KEY `qamera_packshot_job_shop_product` (`id_shop`, `id_product`),
                KEY `qamera_packshot_job_status_submitted` (`status`, `submitted_at`),
                CONSTRAINT `fk_qamera_packshot_job_product_link`
                    FOREIGN KEY (`id_qamera_product_link`)
                    REFERENCES `{$prefix}qamera_product_link` (`id_link`)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset};",
        ];

        foreach ($statements as $sql) {
            if (!Db::getInstance()->execute($sql)) {
                return false;
            }
        }

        if (!$this->migrateProductLinkSchema($prefix)) {
            return false;
        }

        return $this->migratePackshotLinkSchema($prefix);
    }

    /**
     * Brings an existing pre-4.2 `qamera_packshot_link` table up to the
     * Phase-4.2 column set: widens the status ENUM additively, adds
     * `last_synced_at` / `last_error_message` / `updated_at`, and adds
     * the unique index on `qamera_packshot_id` that the webhook
     * event-dispatch UPSERT serialises on. Idempotent — re-running on a
     * fresh-install schema is a no-op (every change is `INFORMATION_SCHEMA`
     * guarded). Existing rows survive untouched.
     */
    private function migratePackshotLinkSchema(string $prefix): bool
    {
        $db = Db::getInstance();
        $table = $prefix . 'qamera_packshot_link';
        $dbName = _DB_NAME_;

        $columns = $db->executeS(sprintf(
            "SELECT COLUMN_NAME, COLUMN_TYPE
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = '%s' AND TABLE_NAME = '%s'",
            pSQL($dbName),
            pSQL($table)
        ));

        if (!is_array($columns)) {
            return false;
        }

        $byName = [];
        foreach ($columns as $row) {
            $byName[$row['COLUMN_NAME']] = $row;
        }

        $alters = [];

        $additions = [
            'last_error_message' => '`last_error_message` TEXT NULL',
            'last_synced_at' => '`last_synced_at` DATETIME NULL',
            // `DEFAULT CURRENT_TIMESTAMP` so adding the column to an
            // existing pre-4.2 table with rows succeeds under strict SQL
            // modes — without a default, MySQL would reject the ALTER (or
            // back-fill the zero-date `'0000-00-00 00:00:00'`, which
            // fails NO_ZERO_DATE). Fresh inserts from the dispatch layer
            // still supply an explicit timestamp.
            'updated_at' => '`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        ];

        foreach ($additions as $name => $definition) {
            if (!isset($byName[$name])) {
                $alters[] = "ALTER TABLE `{$table}` ADD COLUMN {$definition};";
            }
        }

        // Widen the status ENUM additively if any of the new lifecycle
        // values is missing. `COLUMN_TYPE` carries the literal ENUM
        // signature ("enum('pending','ready','archived')"), so a substring
        // probe for the new values is sufficient.
        if (isset($byName['status'])) {
            $columnType = (string) $byName['status']['COLUMN_TYPE'];
            if (
                stripos($columnType, "'failed'") === false
                || stripos($columnType, "'cancelled'") === false
            ) {
                $alters[] = "ALTER TABLE `{$table}` "
                    . "MODIFY COLUMN `status` "
                    . "ENUM('pending','ready','failed','cancelled','archived') "
                    . "NOT NULL DEFAULT 'pending';";
            }
        }

        $indexes = $db->executeS(sprintf(
            "SELECT INDEX_NAME
             FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = '%s' AND TABLE_NAME = '%s'",
            pSQL($dbName),
            pSQL($table)
        ));

        if (!is_array($indexes)) {
            return false;
        }

        $indexNames = array_map(static fn (array $row): string => (string) $row['INDEX_NAME'], $indexes);
        if (!in_array('qamera_packshot_link_qamera_packshot_id', $indexNames, true)) {
            $alters[] = "ALTER TABLE `{$table}` "
                . "ADD UNIQUE KEY `qamera_packshot_link_qamera_packshot_id` (`qamera_packshot_id`);";
        }

        foreach ($alters as $sql) {
            if (!$db->execute($sql)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Brings an existing Phase-1 `qamera_product_link` table up to the
     * Phase-2 column set. Idempotent — each ALTER is guarded by an
     * `INFORMATION_SCHEMA.COLUMNS` probe so a re-install on an already
     * migrated DB is a no-op.
     */
    private function migrateProductLinkSchema(string $prefix): bool
    {
        $db = Db::getInstance();
        $table = $prefix . 'qamera_product_link';
        $dbName = _DB_NAME_;

        $columns = $db->executeS(sprintf(
            "SELECT COLUMN_NAME, IS_NULLABLE, DATA_TYPE, COLUMN_TYPE
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = '%s' AND TABLE_NAME = '%s'",
            pSQL($dbName),
            pSQL($table)
        ));

        if (!is_array($columns)) {
            // Probe failed: we cannot tell which columns exist, so we
            // cannot safely no-op. Fail the install loudly rather than
            // silently leaving a Phase-1 schema in place (which would
            // later cause NOT NULL violations on `qamera_product_id`
            // when the writer tries to insert a `pending` row).
            return false;
        }

        $byName = [];
        foreach ($columns as $row) {
            $byName[$row['COLUMN_NAME']] = $row;
        }

        $alters = [];

        if (
            isset($byName['qamera_product_id'])
            && strtoupper($byName['qamera_product_id']['IS_NULLABLE']) !== 'YES'
        ) {
            $alters[] = "ALTER TABLE `{$table}` MODIFY COLUMN `qamera_product_id` CHAR(36) NULL;";
        }

        $additions = [
            'display_name_snapshot' => '`display_name_snapshot` VARCHAR(500) NOT NULL DEFAULT \'\'',
            'sku_snapshot' => '`sku_snapshot` VARCHAR(100) NULL',
            'description_snapshot' => '`description_snapshot` TEXT NULL',
            'status' => '`status` ENUM(\'pending\',\'registered\',\'error\') NOT NULL DEFAULT \'pending\'',
            'last_error_message' => '`last_error_message` TEXT NULL',
            'last_synced_at' => '`last_synced_at` DATETIME NULL',
            // Phase 4.3 — the storage `asset_id` returned by
            // `QameraApiClient::requestUpload()` for the product's primary
            // uploaded image, populated by ProductImageSyncService on a
            // successful `POST /images`. This is the value sent as
            // `Subject.packshot_asset_id` on job submission. NULL means
            // "never synced an image upstream (or migrated and awaiting
            // re-sync)", which the BO uses to disable the Generate action.
            'qamera_asset_id' => '`qamera_asset_id` CHAR(36) NULL',
            // Phase 4.4 (add-analysis-status-surfacing) — local cache of
            // the upstream Gemini-analysis lifecycle aggregated across
            // the product's `images[]`. NULL on a freshly-migrated row
            // means "never refreshed" and is treated as `pending` by the
            // Generate-readiness gate. The `partial` enum value is
            // reserved for the multi-image future; the v1 single-image
            // flow never emits it.
            'analysis_status' => "`analysis_status` "
                . "ENUM('pending','processing','described','error','partial') "
                . "NULL DEFAULT NULL",
            'analysis_described_count' => '`analysis_described_count` INT UNSIGNED NULL DEFAULT NULL',
            'analysis_total_count' => '`analysis_total_count` INT UNSIGNED NULL DEFAULT NULL',
            'analysis_refreshed_at' => '`analysis_refreshed_at` DATETIME NULL DEFAULT NULL',
        ];

        foreach ($additions as $name => $definition) {
            if (!isset($byName[$name])) {
                $alters[] = "ALTER TABLE `{$table}` ADD COLUMN {$definition};";
            }
        }

        foreach ($alters as $sql) {
            if (!$db->execute($sql)) {
                return false;
            }
        }

        return true;
    }

    private function dropSchema(): bool
    {
        $prefix = _DB_PREFIX_;

        // Order matters: `qamera_packshot_job` FKs to `qamera_product_link`
        // with ON DELETE CASCADE, so it MUST drop first. MySQL evaluates
        // DROP TABLE in list order; listing the child before the parent
        // avoids a `cannot drop … referenced by foreign key` failure on
        // strict-mode servers.
        return Db::getInstance()->execute(
            "DROP TABLE IF EXISTS "
            . "`{$prefix}qamera_packshot_job`, "
            . "`{$prefix}qamera_webhook_delivery`, "
            . "`{$prefix}qamera_packshot_link`, "
            . "`{$prefix}qamera_product_link`;"
        );
    }

    private function registerHooks(): bool
    {
        foreach (self::HOOKS as $hook) {
            if (!$this->module->registerHook($hook)) {
                return false;
            }
        }

        return true;
    }

    private function seedDefaults(): bool
    {
        foreach (self::DEFAULTS as $key => $value) {
            if (Configuration::get($key) === false) {
                Configuration::updateValue($key, $value);
            }
        }

        return true;
    }

    private function purgeConfiguration(): bool
    {
        foreach (array_keys(self::DEFAULTS) as $key) {
            Configuration::deleteByName($key);
        }

        return true;
    }

    /**
     * Phase 4.3 tab structure (replaces the Phase-1 single-tab layout):
     *
     *   IMPROVE
     *   └─ Qamera AI (parent `AdminQameraAi`)
     *      ├─ Products       (`AdminQameraAiProducts`)
     *      ├─ Jobs           (`AdminQameraAiJobs`)
     *      └─ Configuration  (`AdminQameraAiConfiguration`)
     *
     * The Phase-1 install created `AdminQameraAiConfiguration` as a
     * direct child of IMPROVE. This installer migrates it to a child of
     * the new parent when present, so re-installs over an existing 1.x
     * module surface the new menu without a duplicate Configuration link.
     */
    private function installAdminTabs(): bool
    {
        $improveId = (int) Tab::getIdFromClassName('IMPROVE');
        if ($improveId <= 0) {
            $improveId = -1; // hidden orphan fallback for PS builds w/o IMPROVE
        }

        // Migration from the Phase-1 layout is handled entirely by
        // upsertTab() below: when AdminQameraAiConfiguration already
        // exists as a direct child of IMPROVE, the call further down
        // re-uses the same id_tab row, only rewriting id_parent to point
        // at the new AdminQameraAi parent. That preserves the tab id and
        // any employee/profile permissions attached to it — deleting and
        // re-creating would silently reset access control.
        $parentId = $this->upsertTab('AdminQameraAi', 'Qamera AI', $improveId);
        if ($parentId <= 0) {
            return false;
        }

        $children = [
            'AdminQameraAiProducts' => 'Products',
            'AdminQameraAiJobs' => 'Jobs',
            'AdminQameraAiConfiguration' => 'Configuration',
        ];
        foreach ($children as $className => $label) {
            if ($this->upsertTab($className, $label, $parentId) <= 0) {
                return false;
            }
        }

        return true;
    }

    private function upsertTab(string $className, string $label, int $parentId): int
    {
        // Reuse a row if one already exists for this class — covers the
        // re-install-over-existing case and lets us be idempotent under
        // partial install failures.
        $existingId = (int) Tab::getIdFromClassName($className);
        $tab = $existingId > 0 ? new Tab($existingId) : new Tab();

        $tab->active = 1;
        $tab->class_name = $className;
        $tab->module = $this->module->name;
        $tab->id_parent = $parentId;

        $tab->name = [];
        foreach (Language::getLanguages(true) as $language) {
            $tab->name[$language['id_lang']] = $label;
        }

        $ok = $existingId > 0 ? $tab->update() : $tab->add();
        if (!$ok) {
            return 0;
        }
        return (int) $tab->id;
    }

    /**
     * Children first, parent last — PS's Tab::delete refuses to remove
     * a tab that still has children attached.
     */
    private function uninstallAdminTabs(): bool
    {
        $children = [
            'AdminQameraAiProducts',
            'AdminQameraAiJobs',
            'AdminQameraAiConfiguration',
        ];
        foreach ($children as $className) {
            $id = (int) Tab::getIdFromClassName($className);
            if ($id > 0) {
                (new Tab($id))->delete();
            }
        }

        $parentId = (int) Tab::getIdFromClassName('AdminQameraAi');
        if ($parentId > 0) {
            (new Tab($parentId))->delete();
        }

        return true;
    }
}
