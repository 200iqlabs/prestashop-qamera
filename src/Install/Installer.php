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
                `qamera_product_id` CHAR(36) NOT NULL,
                `qamera_product_ref` VARCHAR(200) NOT NULL,
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
