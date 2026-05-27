<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Phase 4.1 — add inbound `qamera_webhook_delivery` table on already-
 * installed Phase-3 deployments. Idempotent — `CREATE TABLE IF NOT EXISTS`
 * is a no-op on fresh installs that ran `Installer::install()` first.
 *
 * @param Module $module Unused; PrestaShop invokes upgrade scripts with
 *                       the module instance for context.
 */
function upgrade_module_1_3_0(/* @phpstan-ignore-line */ $module): bool
{
    $prefix = _DB_PREFIX_;

    $sql = "CREATE TABLE IF NOT EXISTS `{$prefix}qamera_webhook_delivery` (
        `delivery_id` VARCHAR(64) NOT NULL,
        `received_at` DATETIME NOT NULL,
        `event_type` VARCHAR(64) NOT NULL,
        `status` ENUM('accepted','duplicate','rejected') NOT NULL,
        `last_error_message` TEXT NULL,
        `raw_payload` MEDIUMTEXT NOT NULL,
        PRIMARY KEY (`delivery_id`),
        KEY `qamera_webhook_event_type` (`event_type`, `received_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    $db = Db::getInstance();
    if ($db->execute($sql)) {
        return true;
    }

    // Surface the failure: the module-manager UI will mark the upgrade
    // as failed, but without this log line the operator has no clue
    // WHY (e.g. utf8mb4 row-format limits on older MariaDB, or charset
    // mismatch). Without the schema, every webhook POST hits a missing-
    // table error after the version bump and 500s with no diagnostic.
    $error = method_exists($db, 'getMsgError') ? (string) $db->getMsgError() : '';
    PrestaShopLogger::addLog(
        '[QameraAi] upgrade-1.3.0 CREATE TABLE qamera_webhook_delivery failed: ' . $error,
        3,
        null,
        'QameraAiModule',
        null,
        true
    );

    return false;
}
