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
            . '`qamera_product_ref`, `display_name_snapshot` '
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
            $out[$idProduct] = new SyncedProductLink(
                (int) $row['id_link'],
                (int) $row['id_shop'],
                $idProduct,
                isset($row['qamera_image_id']) && $row['qamera_image_id'] !== ''
                    ? (string) $row['qamera_image_id']
                    : null,
                (string) $row['qamera_product_ref'],
                (string) ($row['display_name_snapshot'] ?? '')
            );
        }

        return $out;
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
