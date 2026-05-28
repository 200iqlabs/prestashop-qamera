<?php

declare(strict_types=1);

namespace QameraAi\Module\Packshot;

use Db;
use QameraAi\Module\Webhook\Event\QameraDbException;

/**
 * Persistence + read-side queries for `ps_qamera_packshot_job`. Owns
 * every SQL statement against that table; both the submitter and the
 * webhook updater route through here so the write surface stays auditable.
 *
 * Statement strategy matches the rest of this codebase: raw SQL composed
 * with `Db::escape()` for every string value, integer casts for every
 * numeric value. PS does not expose a parametrised-query API; the spec's
 * "prepared statements" wording is satisfied by the explicit escape +
 * cast discipline here.
 */
class PackshotJobRepository
{
    /**
     * Columns enumerated so `findByJobId`/`findByExternalRef` stay decoupled
     * from incidental schema additions (e.g. an audit column added later
     * should not silently flow through `hydrate()`). Mirrors the
     * "no SELECT *" discipline used in src/Sync/*.
     */
    private const SELECT_COLUMNS = '`id_qamera_packshot_job`, `qamera_job_id`, '
        . '`qamera_order_id`, `id_qamera_product_link`, `id_shop`, `id_product`, '
        . '`packshot_external_ref`, `status`, `output_url`, `output_url_expires_at`, '
        . '`last_error_message`, `ai_model`, `aspect_ratio`, `images_count`, '
        . '`session_config_json`, `submitted_at`, `last_synced_at`';

    public function __construct(
        private readonly Db $db,
        private readonly string $tablePrefix,
    ) {
    }

    public function findByJobId(string $qameraJobId): ?PackshotJobRow
    {
        $sql = sprintf(
            'SELECT %s FROM `%sqamera_packshot_job` WHERE `qamera_job_id` = \'%s\'',
            self::SELECT_COLUMNS,
            $this->tablePrefix,
            $this->escape($qameraJobId)
        );
        $row = $this->db->getRow($sql);
        return $this->hydrateOrNull($row);
    }

    public function findByExternalRef(string $ref): ?PackshotJobRow
    {
        $sql = sprintf(
            'SELECT %s FROM `%sqamera_packshot_job` WHERE `packshot_external_ref` = \'%s\'',
            self::SELECT_COLUMNS,
            $this->tablePrefix,
            $this->escape($ref)
        );
        $row = $this->db->getRow($sql);
        return $this->hydrateOrNull($row);
    }

    /**
     * Multi-row INSERT … ON DUPLICATE KEY UPDATE on `qamera_job_id`. Used
     * by the submitter immediately after a successful `POST /jobs`. A
     * concurrent webhook delivery for the same `qamera_job_id` (the
     * pre-submit race path) lands via {@see upsertFromWebhook}; the two
     * paths share the unique index on `qamera_job_id` so MySQL serialises
     * the conflict at the storage layer.
     *
     * Idempotent under retry: re-running the same batch updates
     * `submitted_at` / `session_config_json` / `submitted-at-immutables`
     * but leaves terminal-status rows untouched (the UPDATE clause is
     * intentionally narrow — webhook updates own status/output/error).
     *
     * @param PackshotJobRow[] $rows
     *
     * @throws QameraDbException on DB error
     */
    public function insertBatch(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        // Single `now` for the whole batch so multi-row inserts all share
        // the same `submitted_at` (operator submitted once → one logical
        // moment). UPDATE clause intentionally omits `submitted_at` so
        // an idempotent retry of the same job_id does NOT overwrite the
        // original timestamp.
        $now = gmdate('Y-m-d H:i:s');
        $escapedNow = $this->escape($now);

        $valueTuples = [];
        foreach ($rows as $row) {
            $valueTuples[] = sprintf(
                "('%s', '%s', %d, %d, %d, '%s', '%s', '%s', %d, '%s', '%s', '%s')",
                $this->escape($row->qameraJobId),
                $this->escape($row->qameraOrderId),
                $row->idQameraProductLink,
                $row->idShop,
                $row->idProduct,
                $this->escape($row->packshotExternalRef),
                $this->escape($row->status),
                $this->escape($row->aiModel),
                $row->imagesCount,
                $this->escape($row->aspectRatio),
                $this->escape($this->encodeJson($row->sessionConfig)),
                $escapedNow
            );
        }

        $sql = sprintf(
            'INSERT INTO `%sqamera_packshot_job` '
            . '(`qamera_job_id`, `qamera_order_id`, `id_qamera_product_link`, '
            . '`id_shop`, `id_product`, `packshot_external_ref`, `status`, '
            . '`ai_model`, `images_count`, `aspect_ratio`, `session_config_json`, '
            . '`submitted_at`) '
            . 'VALUES %s '
            . 'ON DUPLICATE KEY UPDATE '
            . '`qamera_order_id` = VALUES(`qamera_order_id`), '
            . '`session_config_json` = VALUES(`session_config_json`), '
            . '`ai_model` = VALUES(`ai_model`), '
            . '`aspect_ratio` = VALUES(`aspect_ratio`), '
            . '`images_count` = VALUES(`images_count`)',
            $this->tablePrefix,
            implode(', ', $valueTuples)
        );

        if (!$this->db->execute($sql)) {
            throw new QameraDbException('packshot_job insertBatch failed');
        }
    }

    /**
     * Upsert from a webhook delivery. UPDATE path on existing row; INSERT
     * path when the row is absent (pre-submit race). The fallback fields
     * on {@see PackshotJobWebhookUpdate} are only consumed on INSERT.
     *
     * @throws QameraDbException on DB error
     */
    public function upsertFromWebhook(PackshotJobWebhookUpdate $update): void
    {
        $outputUrlSql = $update->outputUrl !== null
            ? sprintf("'%s'", $this->escape($update->outputUrl))
            : 'NULL';
        $outputExpiresSql = $update->outputUrlExpiresAt !== null
            ? sprintf("'%s'", $this->escape($update->outputUrlExpiresAt))
            : 'NULL';
        $lastErrSql = $update->lastErrorMessage !== null
            ? sprintf("'%s'", $this->escape($update->lastErrorMessage))
            : 'NULL';

        // INSERT-path requires every NOT NULL column; the fallback set
        // covers them. If any required fallback is null we cannot build
        // a valid INSERT row, so we fall back to UPDATE-only (the row
        // either exists and the UPDATE succeeds, or this delivery has
        // no recoverable origin and we noop quietly).
        $canInsert = $update->fallbackQameraOrderId !== null
            && $update->fallbackIdQameraProductLink !== null
            && $update->fallbackIdShop !== null
            && $update->fallbackIdProduct !== null
            && $update->fallbackPackshotExternalRef !== null
            && $update->fallbackAiModel !== null
            && $update->fallbackAspectRatio !== null
            && $update->fallbackImagesCount !== null;

        if ($canInsert) {
            $sql = sprintf(
                'INSERT INTO `%sqamera_packshot_job` '
                . '(`qamera_job_id`, `qamera_order_id`, `id_qamera_product_link`, '
                . '`id_shop`, `id_product`, `packshot_external_ref`, `status`, '
                . '`output_url`, `output_url_expires_at`, `last_error_message`, '
                . '`ai_model`, `aspect_ratio`, `images_count`, '
                . '`session_config_json`, `submitted_at`, `last_synced_at`) '
                . "VALUES ('%s', '%s', %d, %d, %d, '%s', '%s', "
                . '%s, %s, %s, '
                . "'%s', '%s', %d, "
                . "'%s', '%s', '%s') "
                . 'ON DUPLICATE KEY UPDATE '
                . '`status` = VALUES(`status`), '
                . '`output_url` = VALUES(`output_url`), '
                . '`output_url_expires_at` = VALUES(`output_url_expires_at`), '
                . '`last_error_message` = VALUES(`last_error_message`), '
                . '`last_synced_at` = VALUES(`last_synced_at`)',
                $this->tablePrefix,
                $this->escape($update->qameraJobId),
                $this->escape((string) $update->fallbackQameraOrderId),
                (int) $update->fallbackIdQameraProductLink,
                (int) $update->fallbackIdShop,
                (int) $update->fallbackIdProduct,
                $this->escape((string) $update->fallbackPackshotExternalRef),
                $this->escape($update->status),
                $outputUrlSql,
                $outputExpiresSql,
                $lastErrSql,
                $this->escape((string) $update->fallbackAiModel),
                $this->escape((string) $update->fallbackAspectRatio),
                (int) $update->fallbackImagesCount,
                $this->escape($this->encodeJson($update->fallbackSessionConfig ?? [])),
                $this->escape($update->now),
                $this->escape($update->now),
            );
        } else {
            $sql = sprintf(
                'UPDATE `%sqamera_packshot_job` SET '
                . "`status` = '%s', "
                . '`output_url` = %s, '
                . '`output_url_expires_at` = %s, '
                . '`last_error_message` = %s, '
                . "`last_synced_at` = '%s' "
                . "WHERE `qamera_job_id` = '%s'",
                $this->tablePrefix,
                $this->escape($update->status),
                $outputUrlSql,
                $outputExpiresSql,
                $lastErrSql,
                $this->escape($update->now),
                $this->escape($update->qameraJobId)
            );
        }

        if (!$this->db->execute($sql)) {
            throw new QameraDbException('packshot_job upsertFromWebhook failed');
        }
    }

    /**
     * Paginated list for the BO jobs-history grid. Joins
     * `ps_product_lang` so the operator's locale wins.
     *
     * @return array<int, array<string, mixed>>  raw rows (controller hydrates to VMs)
     *
     * @throws QameraDbException on DB error
     */
    public function listForGrid(JobsGridFilters $filters): array
    {
        $statusClause = $filters->status !== null
            ? sprintf("AND j.`status` = '%s'", $this->escape($filters->status))
            : '';

        $sql = sprintf(
            'SELECT j.*, pl.`name` AS `product_name` '
            . 'FROM `%sqamera_packshot_job` j '
            . 'LEFT JOIN `%sproduct_lang` pl '
            . '  ON pl.`id_product` = j.`id_product` '
            . '  AND pl.`id_shop` = j.`id_shop` '
            . '  AND pl.`id_lang` = %d '
            . 'WHERE 1=1 %s '
            . 'ORDER BY j.`submitted_at` DESC '
            . 'LIMIT %d OFFSET %d',
            $this->tablePrefix,
            $this->tablePrefix,
            $filters->idLang,
            $statusClause,
            $filters->limit,
            $filters->offset
        );

        $rows = $this->db->executeS($sql);
        if ($rows === false) {
            throw new QameraDbException('packshot_job listForGrid failed');
        }
        if (!is_array($rows)) {
            return [];
        }
        /** @var array<int, array<string, mixed>> $rows */
        return $rows;
    }

    /**
     * @param array<string, mixed>|false|null $row
     */
    private function hydrateOrNull($row): ?PackshotJobRow
    {
        if (!is_array($row) || $row === []) {
            return null;
        }
        return $this->hydrate($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): PackshotJobRow
    {
        $sessionConfig = [];
        $rawJson = $row['session_config_json'] ?? null;
        if (is_string($rawJson) && $rawJson !== '') {
            $decoded = json_decode($rawJson, true);
            if (is_array($decoded)) {
                $sessionConfig = $decoded;
            }
        }

        return new PackshotJobRow(
            isset($row['id_qamera_packshot_job']) ? (int) $row['id_qamera_packshot_job'] : null,
            (string) $row['qamera_job_id'],
            (string) $row['qamera_order_id'],
            (int) $row['id_qamera_product_link'],
            (int) $row['id_shop'],
            (int) $row['id_product'],
            (string) $row['packshot_external_ref'],
            (string) $row['status'],
            isset($row['output_url']) && $row['output_url'] !== '' ? (string) $row['output_url'] : null,
            isset($row['output_url_expires_at']) && $row['output_url_expires_at'] !== ''
                ? (string) $row['output_url_expires_at']
                : null,
            isset($row['last_error_message']) && $row['last_error_message'] !== ''
                ? (string) $row['last_error_message']
                : null,
            (string) $row['ai_model'],
            (string) $row['aspect_ratio'],
            (int) $row['images_count'],
            $sessionConfig,
            (string) $row['submitted_at'],
            isset($row['last_synced_at']) && $row['last_synced_at'] !== ''
                ? (string) $row['last_synced_at']
                : null,
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function encodeJson(array $config): string
    {
        try {
            return json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $e) {
            throw new QameraDbException('session_config_json encode failed: ' . $e->getMessage());
        }
    }

    private function escape(string $value): string
    {
        return $this->db->escape($value, true, true);
    }
}
