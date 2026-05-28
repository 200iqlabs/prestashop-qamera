<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Phase 4.4 (add-analysis-status-surfacing) — extend
 * `qamera_product_link` with the local cache of the upstream Gemini
 * image-analysis lifecycle. Mirrors the additions in
 * `Installer::migrateProductLinkSchema()` so fresh installs and upgrades
 * converge to the same shape. INFORMATION_SCHEMA-guarded so re-running
 * the upgrade on an already-migrated DB is a no-op.
 *
 * @param Module $module Unused; PrestaShop invokes upgrade scripts with
 *                       the module instance for context.
 */
function upgrade_module_1_4_0(/* @phpstan-ignore-line */ $module): bool
{
    $db = Db::getInstance();
    $prefix = _DB_PREFIX_;
    $table = $prefix . 'qamera_product_link';
    $dbName = _DB_NAME_;

    $columns = $db->executeS(sprintf(
        "SELECT COLUMN_NAME
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = '%s' AND TABLE_NAME = '%s'",
        pSQL($dbName),
        pSQL($table)
    ));

    if (!is_array($columns)) {
        PrestaShopLogger::addLog(
            '[QameraAi] upgrade-1.4.0 INFORMATION_SCHEMA probe failed for ' . $table,
            3,
            null,
            'QameraAiModule',
            null,
            true
        );
        return false;
    }

    $present = [];
    foreach ($columns as $row) {
        $present[$row['COLUMN_NAME']] = true;
    }

    $additions = [
        'analysis_status' => "`analysis_status` "
            . "ENUM('pending','processing','described','error','partial') "
            . "NULL DEFAULT NULL",
        'analysis_described_count' => '`analysis_described_count` INT UNSIGNED NULL DEFAULT NULL',
        'analysis_total_count' => '`analysis_total_count` INT UNSIGNED NULL DEFAULT NULL',
        'analysis_refreshed_at' => '`analysis_refreshed_at` DATETIME NULL DEFAULT NULL',
    ];

    foreach ($additions as $name => $definition) {
        if (isset($present[$name])) {
            continue;
        }
        $sql = "ALTER TABLE `{$table}` ADD COLUMN {$definition};";
        if ($db->execute($sql)) {
            continue;
        }
        $error = method_exists($db, 'getMsgError') ? (string) $db->getMsgError() : '';
        PrestaShopLogger::addLog(
            '[QameraAi] upgrade-1.4.0 ADD COLUMN ' . $name . ' failed: ' . $error,
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
