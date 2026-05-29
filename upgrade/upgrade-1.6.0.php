<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * fix-webhook-payload-contract — drop the dead `qamera_packshot_link`
 * table. It was keyed on `qamera_packshot_id`, which the real webhook wire
 * body never carries, and nothing read it; the per-job mirror
 * `qamera_packshot_job` (keyed on `job.id`) is the sole webhook-driven
 * job-state store. `DROP TABLE IF EXISTS` is idempotent.
 *
 * @param Module $module Unused; PrestaShop invokes upgrade scripts with
 *                       the module instance for context.
 */
function upgrade_module_1_6_0(/* @phpstan-ignore-line */ $module): bool
{
    $db = Db::getInstance();
    $table = _DB_PREFIX_ . 'qamera_packshot_link';

    if ($db->execute("DROP TABLE IF EXISTS `{$table}`;")) {
        return true;
    }

    $error = method_exists($db, 'getMsgError') ? (string) $db->getMsgError() : '';
    PrestaShopLogger::addLog(
        '[QameraAi] upgrade-1.6.0 DROP TABLE ' . $table . ' failed: ' . $error,
        3,
        null,
        'QameraAiModule',
        null,
        true
    );

    return false;
}
