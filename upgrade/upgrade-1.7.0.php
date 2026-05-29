<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * add-packshot-acceptance-flow (Phase 4.4, D1) — create the
 * `ps_qamera_packshot_review` table on an existing install. Holds the local
 * voting state for stage-1 `job_type='packshot'` jobs, keyed on
 * `qamera_job_id`. No FK to `qamera_product_link` (review rows are matched
 * via the parsed `product_ref` and must outlive a re-created product link).
 *
 * `CREATE TABLE IF NOT EXISTS` is idempotent — a re-run, or a fresh install
 * that already built the table via {@see Installer::createSchema()}, is a
 * no-op. Definition is kept verbatim-equal to the installer's.
 *
 * @param Module $module Unused; PrestaShop invokes upgrade scripts with the
 *                       module instance for context.
 */
function upgrade_module_1_7_0(/* @phpstan-ignore-line */ $module): bool
{
    $db = Db::getInstance();
    $prefix = _DB_PREFIX_;
    $charset = 'utf8mb4';

    $sql = "CREATE TABLE IF NOT EXISTS `{$prefix}qamera_packshot_review` (
        `id_qamera_packshot_review` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `qamera_job_id` CHAR(36) NOT NULL,
        `id_shop` INT UNSIGNED NOT NULL,
        `id_product` INT UNSIGNED NOT NULL,
        `product_ref` VARCHAR(200) NOT NULL,
        `asset_url` TEXT NULL,
        `voting` ENUM('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
        `voting_at` DATETIME NULL,
        `generated_at` DATETIME NOT NULL,
        PRIMARY KEY (`id_qamera_packshot_review`),
        UNIQUE KEY `qamera_packshot_review_job_id` (`qamera_job_id`),
        KEY `qamera_packshot_review_product_ref` (`product_ref`, `voting`),
        KEY `qamera_packshot_review_shop_product` (`id_shop`, `id_product`),
        KEY `qamera_packshot_review_voting` (`voting`, `generated_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$charset};";

    if ($db->execute($sql)) {
        return true;
    }

    $error = method_exists($db, 'getMsgError') ? (string) $db->getMsgError() : '';
    PrestaShopLogger::addLog(
        '[QameraAi] upgrade-1.7.0 CREATE TABLE qamera_packshot_review failed: ' . $error,
        3,
        null,
        'QameraAiModule',
        null,
        true
    );

    return false;
}
