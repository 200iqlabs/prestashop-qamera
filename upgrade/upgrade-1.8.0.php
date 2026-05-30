<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * add-packshot-output-downloader (Phase 4.5, D4) — create the
 * `ps_qamera_imported_output` ledger on an existing install. One row per
 * output imported into the PrestaShop gallery, keyed UNIQUE on
 * `(qamera_job_id, output_index)`. Doubles as dedup ledger (import-once),
 * partial-retry cursor, and Qamera-origin marker for the sync-loop guard.
 *
 * `CREATE TABLE IF NOT EXISTS` is idempotent — a re-run, or a fresh install
 * that already built the table via {@see Installer::createSchema()}, is a
 * no-op. Definition is kept verbatim-equal to the installer's. No new admin
 * tab: the "Download to shop" action rides on the existing Jobs history view.
 *
 * @param Module $module Unused; PrestaShop invokes upgrade scripts with the
 *                       module instance for context.
 */
function upgrade_module_1_8_0(/* @phpstan-ignore-line */ $module): bool
{
    $db = Db::getInstance();
    $prefix = _DB_PREFIX_;
    $charset = 'utf8mb4';

    $sql = "CREATE TABLE IF NOT EXISTS `{$prefix}qamera_imported_output` (
        `id_qamera_imported_output` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `qamera_job_id` CHAR(36) NOT NULL,
        `output_index` INT UNSIGNED NOT NULL,
        `output_type` VARCHAR(64) NOT NULL,
        `id_shop` INT UNSIGNED NOT NULL,
        `id_product` INT UNSIGNED NOT NULL,
        `id_image` INT UNSIGNED NULL,
        `imported_at` DATETIME NOT NULL,
        PRIMARY KEY (`id_qamera_imported_output`),
        UNIQUE KEY `qamera_imported_output_job_index` (`qamera_job_id`, `output_index`),
        KEY `qamera_imported_output_shop_product` (`id_product`, `id_shop`),
        KEY `qamera_imported_output_id_image` (`id_image`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$charset};";

    if (!$db->execute($sql)) {
        $error = method_exists($db, 'getMsgError') ? (string) $db->getMsgError() : '';
        PrestaShopLogger::addLog(
            '[QameraAi] upgrade-1.8.0 CREATE TABLE qamera_imported_output failed: ' . $error,
            3,
            null,
            'QameraAiModule',
            null,
            true
        );
        return false;
    }

    return true;
}
