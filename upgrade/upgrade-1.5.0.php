<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * fix-packshot-asset-id-mismatch — rename `qamera_image_id` →
 * `qamera_asset_id` on `qamera_product_link`. The former column
 * incorrectly stored the logical `ImageResponse.imageId`; the column that
 * feeds `Subject.packshot_asset_id` must hold the storage `asset_id`
 * minted by `requestUpload()`.
 *
 * Two steps, both INFORMATION_SCHEMA-guarded so re-running on an
 * already-migrated DB is a no-op:
 *
 *   1. If `qamera_image_id` is present AND `qamera_asset_id` is absent,
 *      rename it in place via `ALTER TABLE ... CHANGE COLUMN`.
 *   2. `UPDATE ... SET qamera_asset_id = NULL` — the carried-over logical
 *      ids are wrong and MUST NOT survive (a non-null wrong value would
 *      pass the Generate gate and reproduce the silent "No source upload
 *      found" generation failure). The operator re-saves affected
 *      products so the `actionWatermark` hook repopulates the correct id.
 *
 * On any failed statement: log at severity 3 and return false (same
 * convention as `upgrade-1.4.0.php`).
 *
 * @param Module $module Unused; PrestaShop invokes upgrade scripts with
 *                       the module instance for context.
 */
function upgrade_module_1_5_0(/* @phpstan-ignore-line */ $module): bool
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
            '[QameraAi] upgrade-1.5.0 INFORMATION_SCHEMA probe failed for ' . $table,
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

    // Step 1: rename in place only when the old column exists and the new
    // one does not. Re-running after the rename fails this guard → no-op.
    if (isset($present['qamera_image_id']) && !isset($present['qamera_asset_id'])) {
        $sql = "ALTER TABLE `{$table}` "
            . "CHANGE COLUMN `qamera_image_id` `qamera_asset_id` CHAR(36) NULL;";
        if (!$db->execute($sql)) {
            $error = method_exists($db, 'getMsgError') ? (string) $db->getMsgError() : '';
            PrestaShopLogger::addLog(
                '[QameraAi] upgrade-1.5.0 CHANGE COLUMN qamera_image_id failed: ' . $error,
                3,
                null,
                'QameraAiModule',
                null,
                true
            );
            return false;
        }
    }

    // Step 2: null the carried-over (wrong) values so the Generate gate
    // cannot pass on stale logical ids. Only meaningful once the column
    // exists under the new name; on a fresh install it is already NULL.
    if (isset($present['qamera_asset_id']) || isset($present['qamera_image_id'])) {
        $sql = "UPDATE `{$table}` SET `qamera_asset_id` = NULL;";
        if (!$db->execute($sql)) {
            $error = method_exists($db, 'getMsgError') ? (string) $db->getMsgError() : '';
            PrestaShopLogger::addLog(
                '[QameraAi] upgrade-1.5.0 UPDATE qamera_asset_id = NULL failed: ' . $error,
                3,
                null,
                'QameraAiModule',
                null,
                true
            );
            return false;
        }
    }

    return true;
}
