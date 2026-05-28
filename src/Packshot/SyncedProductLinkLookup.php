<?php

declare(strict_types=1);

namespace QameraAi\Module\Packshot;

use Db;
use QameraAi\Module\Webhook\Event\QameraDbException;

/**
 * Tiny read-side wrapper over `ps_qamera_product_link` for the submitter
 * + webhook upserter. Kept separate from `PackshotJobRepository` so that
 * class stays focused on the new `ps_qamera_packshot_job` table.
 *
 * SELECTs are intentionally narrow — only the columns the consumers
 * actually need (id_link, qamera_image_id, qamera_product_ref,
 * display_name_snapshot). PHPCS sniff at PR review time: no `SELECT *`.
 */
/**
 * NOT `final` so tests can substitute an in-memory fake — see
 * `tests/Support/FakeSyncedProductLinkLookup.php`. Same pattern as
 * {@see PackshotJobUpdater} / {@see \QameraAi\Module\Webhook\Event\PackshotLinkUpdater}.
 */
class SyncedProductLinkLookup
{
    public function __construct(
        private readonly Db $db,
        private readonly string $tablePrefix,
    ) {
    }

    /**
     * @param int[] $idProducts
     *
     * @return array<int, SyncedProductLink>  keyed by id_product
     *
     * @throws QameraDbException on DB error
     */
    public function loadByProductIds(int $idShop, array $idProducts): array
    {
        if ($idProducts === []) {
            return [];
        }
        // Whitelist: cast every id to int so the IN(...) clause can never
        // carry user-influenced strings. PS's Db::escape covers string
        // values but the list itself is composed here.
        $idsCsv = implode(',', array_map(static fn (int $i): int => $i, $idProducts));

        $sql = sprintf(
            'SELECT `id_link`, `id_shop`, `id_product`, `qamera_image_id`, '
            . '`qamera_product_ref`, `display_name_snapshot`, '
            . '`analysis_status`, `analysis_described_count`, '
            . '`analysis_total_count`, `analysis_refreshed_at` '
            . 'FROM `%sqamera_product_link` '
            . 'WHERE `id_shop` = %d AND `id_product` IN (%s)',
            $this->tablePrefix,
            $idShop,
            $idsCsv
        );

        $rows = $this->db->executeS($sql);
        if ($rows === false) {
            throw new QameraDbException('product_link lookup failed');
        }
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $idProduct = (int) $row['id_product'];
            $out[$idProduct] = $this->hydrate($row);
        }

        return $out;
    }

    /**
     * Paginated grid feed for the BO products page. Includes every
     * registered product link (synced OR pending) so the grid can
     * surface both states with one query. Unsynced rows
     * (qamera_image_id IS NULL) render with a disabled Generate
     * action — the filter happens client-side via
     * {@see SyncedProductLink::canGenerate()}.
     *
     * @return SyncedProductLink[]  ordered by id_product DESC (newest first)
     *
     * @throws \QameraAi\Module\Webhook\Event\QameraDbException on DB error
     */
    public function listForGrid(int $idShop, int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);

        $sql = sprintf(
            'SELECT `id_link`, `id_shop`, `id_product`, `qamera_image_id`, '
            . '`qamera_product_ref`, `display_name_snapshot`, `status`, `last_synced_at`, '
            . '`analysis_status`, `analysis_described_count`, '
            . '`analysis_total_count`, `analysis_refreshed_at` '
            . 'FROM `%sqamera_product_link` '
            . 'WHERE `id_shop` = %d '
            . 'ORDER BY `id_product` DESC '
            . 'LIMIT %d OFFSET %d',
            $this->tablePrefix,
            $idShop,
            $limit,
            $offset
        );

        $rows = $this->db->executeS($sql);
        if ($rows === false) {
            throw new \QameraAi\Module\Webhook\Event\QameraDbException('product_link grid query failed');
        }
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->hydrate($row);
        }

        return $out;
    }

    /**
     * Surrogate `id_link` → full link projection, used by the BO status
     * endpoint to look up a single row by its primary key. Returns null
     * when the row does not exist or belongs to a different shop.
     *
     * @throws QameraDbException on DB error
     */
    public function findByIdLink(int $idShop, int $idLink): ?SyncedProductLink
    {
        $sql = sprintf(
            'SELECT `id_link`, `id_shop`, `id_product`, `qamera_image_id`, '
            . '`qamera_product_ref`, `display_name_snapshot`, `status`, `last_synced_at`, '
            . '`analysis_status`, `analysis_described_count`, '
            . '`analysis_total_count`, `analysis_refreshed_at` '
            . 'FROM `%sqamera_product_link` '
            . 'WHERE `id_shop` = %d AND `id_link` = %d',
            $this->tablePrefix,
            $idShop,
            $idLink
        );

        // NOTE: Db::getRow() auto-appends `LIMIT 1`; we MUST NOT include
        // it in the SQL string ourselves or the resulting `LIMIT 1 LIMIT 1`
        // is a parse error (caught the first time during smoke).
        $row = $this->db->getRow($sql);
        if ($row === false) {
            throw new QameraDbException('product_link findByIdLink failed');
        }
        if (!is_array($row) || $row === []) {
            return null;
        }

        return $this->hydrate($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): SyncedProductLink
    {
        return new SyncedProductLink(
            (int) $row['id_link'],
            (int) $row['id_shop'],
            (int) $row['id_product'],
            isset($row['qamera_image_id']) && $row['qamera_image_id'] !== ''
                ? (string) $row['qamera_image_id']
                : null,
            (string) $row['qamera_product_ref'],
            (string) ($row['display_name_snapshot'] ?? ''),
            isset($row['status']) ? (string) $row['status'] : null,
            isset($row['last_synced_at']) && $row['last_synced_at'] !== ''
                ? (string) $row['last_synced_at']
                : null,
            isset($row['analysis_status']) && $row['analysis_status'] !== ''
                ? (string) $row['analysis_status']
                : null,
            isset($row['analysis_described_count']) && $row['analysis_described_count'] !== null
                ? (int) $row['analysis_described_count']
                : null,
            isset($row['analysis_total_count']) && $row['analysis_total_count'] !== null
                ? (int) $row['analysis_total_count']
                : null,
            isset($row['analysis_refreshed_at']) && $row['analysis_refreshed_at'] !== ''
                ? (string) $row['analysis_refreshed_at']
                : null,
        );
    }

    public function countForShop(int $idShop): int
    {
        $sql = sprintf(
            'SELECT COUNT(*) AS `n` FROM `%sqamera_product_link` WHERE `id_shop` = %d',
            $this->tablePrefix,
            $idShop
        );
        $row = $this->db->getRow($sql);
        if (!is_array($row) || !isset($row['n'])) {
            return 0;
        }
        return (int) $row['n'];
    }

    /**
     * Resolve a single `(id_shop, id_product)` to the surrogate `id_link`
     * — used by the webhook pre-submit-race path so a freshly-arrived
     * `job.*` delivery can insert a stub row with the right FK before
     * the submitter has had a chance to persist its own row.
     *
     * @throws QameraDbException on DB error
     */
    public function findIdLink(int $idShop, int $idProduct): ?int
    {
        $sql = sprintf(
            'SELECT `id_link` FROM `%sqamera_product_link` '
            . 'WHERE `id_shop` = %d AND `id_product` = %d',
            $this->tablePrefix,
            $idShop,
            $idProduct
        );
        $row = $this->db->getRow($sql);
        if ($row === false) {
            return null;
        }
        if (!is_array($row) || !isset($row['id_link'])) {
            return null;
        }
        return (int) $row['id_link'];
    }
}
