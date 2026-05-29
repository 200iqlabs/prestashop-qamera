<?php

declare(strict_types=1);

namespace QameraAi\Module\Packshot\Acceptance;

use Db;
use QameraAi\Module\Webhook\Event\QameraDbException;

/**
 * Persistence + read-side queries for `ps_qamera_packshot_review`
 * (add-packshot-acceptance-flow). Owns every SQL statement against that
 * table; the webhook branch, the vote service, and the grid gate all route
 * through here so the write surface stays auditable.
 *
 * Statement strategy matches the rest of this codebase: raw SQL composed
 * with `Db::escape()` for every string value, integer casts for every
 * numeric value. PS exposes no parametrised-query API; the spec's
 * "prepared statements" wording is satisfied by the explicit escape +
 * cast discipline here. Mirrors {@see \QameraAi\Module\Packshot\PackshotJobRepository}.
 */
class PackshotReviewRepository
{
    private const SELECT_COLUMNS = '`id_qamera_packshot_review`, `qamera_job_id`, '
        . '`id_shop`, `id_product`, `product_ref`, `asset_url`, `voting`, '
        . '`voting_at`, `generated_at`';

    public function __construct(
        private readonly Db $db,
        private readonly string $tablePrefix,
    ) {
    }

    public function findByJobId(string $qameraJobId): ?PackshotReviewRow
    {
        $sql = sprintf(
            'SELECT %s FROM `%sqamera_packshot_review` WHERE `qamera_job_id` = \'%s\'',
            self::SELECT_COLUMNS,
            $this->tablePrefix,
            $this->escape($qameraJobId)
        );
        return $this->hydrateOrNull($this->db->getRow($sql));
    }

    /**
     * Upsert from a webhook delivery (`job.completed`, `job_type='packshot'`).
     * INSERT on first delivery (voting defaults to 'pending'); on a
     * re-delivery of the same `qamera_job_id` the ON DUPLICATE KEY UPDATE
     * refreshes only `asset_url` + `generated_at` and intentionally leaves
     * `voting`/`voting_at` untouched, so a packshot the operator has already
     * accepted/rejected never silently reverts to pending on a retry.
     *
     * @throws QameraDbException on DB error
     */
    public function upsertFromWebhook(PackshotReviewRow $row): void
    {
        $assetUrlSql = $row->assetUrl !== null
            ? sprintf("'%s'", $this->escape($row->assetUrl))
            : 'NULL';

        $sql = sprintf(
            'INSERT INTO `%sqamera_packshot_review` '
            . '(`qamera_job_id`, `id_shop`, `id_product`, `product_ref`, '
            . '`asset_url`, `voting`, `generated_at`) '
            . "VALUES ('%s', %d, %d, '%s', %s, '%s', '%s') "
            . 'ON DUPLICATE KEY UPDATE '
            . '`asset_url` = VALUES(`asset_url`), '
            . '`generated_at` = VALUES(`generated_at`)',
            $this->tablePrefix,
            $this->escape($row->qameraJobId),
            $row->idShop,
            $row->idProduct,
            $this->escape($row->productRef),
            $assetUrlSql,
            $this->escape(
                in_array($row->voting, PackshotReviewRow::VOTINGS, true)
                    ? $row->voting
                    : PackshotReviewRow::VOTING_PENDING
            ),
            $this->escape($row->generatedAt)
        );

        if (!$this->db->execute($sql)) {
            throw new QameraDbException('packshot_review upsertFromWebhook failed');
        }
    }

    /**
     * Pending review queue for the "Packshots — review" BO view. Joins
     * `ps_product_lang` so the operator's locale wins. Newest first — the
     * queue is small (one row per ungraded packshot job), so v1 is unpaged.
     *
     * @return array<int, array<string, mixed>>  raw rows (controller hydrates to VMs)
     *
     * @throws QameraDbException on DB error
     */
    public function listPending(int $idLang): array
    {
        $sql = sprintf(
            'SELECT r.*, pl.`name` AS `product_name` '
            . 'FROM `%sqamera_packshot_review` r '
            . 'LEFT JOIN `%sproduct_lang` pl '
            . '  ON pl.`id_product` = r.`id_product` '
            . '  AND pl.`id_shop` = r.`id_shop` '
            . '  AND pl.`id_lang` = %d '
            . "WHERE r.`voting` = '%s' "
            . 'ORDER BY r.`generated_at` DESC',
            $this->tablePrefix,
            $this->tablePrefix,
            $idLang,
            $this->escape(PackshotReviewRow::VOTING_PENDING)
        );

        $rows = $this->db->executeS($sql);
        if ($rows === false) {
            throw new QameraDbException('packshot_review listPending failed');
        }
        if (!is_array($rows)) {
            return [];
        }
        /** @var array<int, array<string, mixed>> $rows */
        return $rows;
    }

    /**
     * Flip the local voting state after a successful `POST /jobs/{id}/accept`
     * or `/reject`. Caller passes the already-validated voting value and the
     * timestamp (`gmdate('Y-m-d H:i:s')`); this method does not call the API.
     *
     * @throws QameraDbException on DB error or unknown voting value
     */
    public function setVoting(string $qameraJobId, string $voting, string $votingAt): void
    {
        if (!in_array($voting, PackshotReviewRow::VOTINGS, true)) {
            throw new QameraDbException('packshot_review setVoting: unknown voting ' . $voting);
        }

        $sql = sprintf(
            'UPDATE `%sqamera_packshot_review` SET '
            . "`voting` = '%s', `voting_at` = '%s' "
            . "WHERE `qamera_job_id` = '%s'",
            $this->tablePrefix,
            $this->escape($voting),
            $this->escape($votingAt),
            $this->escape($qameraJobId)
        );

        if (!$this->db->execute($sql)) {
            throw new QameraDbException('packshot_review setVoting failed');
        }
    }

    /**
     * Grid gate for "Generate photo-shoot": true iff the product's
     * `product_ref` has at least one locally-accepted packshot review row.
     * Backed by the `(product_ref, voting)` composite index.
     *
     * @throws QameraDbException on DB error
     */
    public function hasAcceptedForProductRef(string $productRef): bool
    {
        $sql = sprintf(
            'SELECT 1 FROM `%sqamera_packshot_review` '
            . "WHERE `product_ref` = '%s' AND `voting` = '%s' LIMIT 1",
            $this->tablePrefix,
            $this->escape($productRef),
            $this->escape(PackshotReviewRow::VOTING_ACCEPTED)
        );

        $row = $this->db->getRow($sql);
        return is_array($row) && $row !== [];
    }

    /**
     * @param array<string, mixed>|false|null $row
     */
    private function hydrateOrNull($row): ?PackshotReviewRow
    {
        if (!is_array($row) || $row === []) {
            return null;
        }
        return $this->hydrate($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): PackshotReviewRow
    {
        return new PackshotReviewRow(
            isset($row['id_qamera_packshot_review']) ? (int) $row['id_qamera_packshot_review'] : null,
            (string) $row['qamera_job_id'],
            (int) $row['id_shop'],
            (int) $row['id_product'],
            (string) $row['product_ref'],
            isset($row['asset_url']) && $row['asset_url'] !== '' ? (string) $row['asset_url'] : null,
            (string) $row['voting'],
            isset($row['voting_at']) && $row['voting_at'] !== '' ? (string) $row['voting_at'] : null,
            (string) $row['generated_at'],
        );
    }

    private function escape(string $value): string
    {
        return $this->db->escape($value, true, true);
    }
}
