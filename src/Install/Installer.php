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
                `status` ENUM('pending','ready','archived') NOT NULL DEFAULT 'pending',
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id_link`),
                UNIQUE KEY `qamera_packshot_link_ref` (`qamera_packshot_ref`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset};",
        ];

        foreach ($statements as $sql) {
            if (!Db::getInstance()->execute($sql)) {
                return false;
            }
        }

        return $this->migrateProductLinkSchema($prefix);
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

        return Db::getInstance()->execute(
            "DROP TABLE IF EXISTS `{$prefix}qamera_packshot_link`, `{$prefix}qamera_product_link`;"
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

    private function installAdminTabs(): bool
    {
        $tab = new Tab();
        $tab->active = 1;
        $tab->class_name = 'AdminQameraAiConfiguration';
        $tab->name = [];
        foreach (Language::getLanguages(true) as $language) {
            $tab->name[$language['id_lang']] = 'Qamera AI';
        }

        // Attach under the IMPROVE root so the module has a visible menu
        // entry in the back-office sidebar instead of forcing operators
        // to reach Configure via Module Manager. Falls back to -1 (hidden
        // orphan) on PS builds that don't expose the IMPROVE class slug.
        $parentId = (int) Tab::getIdFromClassName('IMPROVE');
        $tab->id_parent = $parentId > 0 ? $parentId : -1;
        $tab->module = $this->module->name;

        return (bool) $tab->add();
    }

    private function uninstallAdminTabs(): bool
    {
        $idTab = (int) Tab::getIdFromClassName('AdminQameraAiConfiguration');
        if ($idTab > 0) {
            $tab = new Tab($idTab);

            return (bool) $tab->delete();
        }

        return true;
    }
}
