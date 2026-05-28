<?php

declare(strict_types=1);

namespace QameraAi\Module\Webhook\Event;

use Db;

/**
 * Thin DB wrapper for the single `INSERT … ON DUPLICATE KEY UPDATE`
 * statement that the four `job.*` event handlers issue against
 * `qamera_packshot_link`. Keyed on the unique index over
 * `qamera_packshot_id` (added by Installer::migratePackshotLinkSchema()),
 * so concurrent deliveries for the same packshot serialise on the index
 * at MySQL level rather than at the application layer.
 *
 * Immutable columns on the row (`id_shop`, `id_product`,
 * `qamera_packshot_ref`, `created_at`) are intentionally absent from the
 * `ON DUPLICATE KEY UPDATE` clause: re-deliveries must NOT clobber the
 * shop/product the row was originally created for, even if a future
 * upstream worker sends a payload with a different external_ref by
 * mistake. The unique key is per-`qamera_packshot_id`, so a different
 * `(shopId, productId)` arriving on the same packshot id is treated as
 * a hostile / buggy upstream message — the row's original ownership
 * stands.
 */
class PackshotLinkUpdater
{
    public function __construct(
        private readonly Db $db,
        private readonly string $tablePrefix
    ) {
    }

    /**
     * @param array{
     *     qamera_packshot_id: string,
     *     qamera_packshot_ref: string,
     *     qamera_job_id: ?string,
     *     id_shop: int,
     *     id_product: int,
     *     status: string,
     *     last_error_message: ?string,
     *     now: string
     * } $row
     * @return bool true = fresh insert, false = updated an existing row
     * @throws QameraDbException on DB error
     */
    public function upsertByPackshotId(array $row): bool
    {
        $jobIdSql = $row['qamera_job_id'] === null
            ? 'NULL'
            : sprintf("'%s'", $this->escape($row['qamera_job_id']));
        $lastErrSql = $row['last_error_message'] === null
            ? 'NULL'
            : sprintf("'%s'", $this->escape($row['last_error_message']));

        $sql = sprintf(
            'INSERT INTO `%sqamera_packshot_link` '
            . '(`id_product`, `id_shop`, `qamera_packshot_id`, `qamera_packshot_ref`, '
            . '`qamera_job_id`, `status`, `last_error_message`, `last_synced_at`, '
            . '`created_at`, `updated_at`) '
            . "VALUES (%d, %d, '%s', '%s', %s, '%s', %s, '%s', '%s', '%s') "
            . 'ON DUPLICATE KEY UPDATE '
            . '`qamera_job_id` = VALUES(`qamera_job_id`), '
            . '`status` = VALUES(`status`), '
            . '`last_error_message` = VALUES(`last_error_message`), '
            . '`last_synced_at` = VALUES(`last_synced_at`), '
            . '`updated_at` = VALUES(`updated_at`);',
            $this->tablePrefix,
            $row['id_product'],
            $row['id_shop'],
            $this->escape($row['qamera_packshot_id']),
            $this->escape($row['qamera_packshot_ref']),
            $jobIdSql,
            $this->escape($row['status']),
            $lastErrSql,
            $this->escape($row['now']),
            $this->escape($row['now']),
            $this->escape($row['now'])
        );

        if (!$this->db->execute($sql)) {
            throw new QameraDbException('packshot_link upsert failed');
        }

        // MySQL returns 1 for fresh insert, 2 for ON DUPLICATE KEY UPDATE
        // when at least one column actually changed, 0 when the update
        // matched but changed nothing. Treat anything >= 2 as an update;
        // 1 means a new row.
        return (int) $this->db->Affected_Rows() === 1;
    }

    private function escape(string $value): string
    {
        return $this->db->escape($value, true, true);
    }
}
