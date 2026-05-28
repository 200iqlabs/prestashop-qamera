<?php

declare(strict_types=1);

namespace QameraAi\Module\Webhook\Event;

use Db;

/**
 * Refreshes `ps_qamera_product_link.last_synced_at` for a given
 * `(id_shop, id_product)` pair. Heartbeat ONLY — never touches `status`,
 * `qamera_product_id`, or `last_error_message`. Those columns are owned
 * by Phase 3 (`product-image-sync`) and the dispatch layer must not
 * clobber them.
 *
 * `touch()` returns false when no row matched (the dispatcher logs a
 * WARNING and continues — typical cause is a delivery for a product
 * this shop doesn't own, e.g. a shared installation_id between PS
 * instances). `false` is NEVER an error, so DB exceptions throw.
 */
class ProductLinkHeartbeat
{
    public function __construct(
        private readonly Db $db,
        private readonly string $tablePrefix
    ) {
    }

    /**
     * @return bool true when one row was updated, false when no matching row
     * @throws QameraDbException on DB error
     */
    public function touch(int $idShop, int $idProduct): bool
    {
        // Existence probe BEFORE the UPDATE.
        //
        // Why not infer from Affected_Rows() alone: MySQL with the default
        // PrestaShop connection flags reports `affected = 0` for an UPDATE
        // that matches a row but changes no column. Our SET clause writes
        // only NOW() with second resolution, so a second touch() for the
        // same (id_shop, id_product) within the same second on a row that
        // already carries those timestamps reports `affected = 0` — which
        // looks identical to "no row matched". Treating that as "product
        // unknown" makes the handler silently skip the packshot upsert.
        //
        // A dedicated SELECT is one extra round-trip per dispatch (typical
        // dispatch is <5ms; +0.5ms is in the noise) but removes the
        // false-negative entirely.
        // No explicit `LIMIT 1`: PrestaShop's Db::getRow() auto-appends
        // `LIMIT 1` to the query, and a double-LIMIT triggers `syntax
        // error near 'LIMIT 1'`. The PS contract guarantees a single row.
        $probeSql = sprintf(
            'SELECT 1 FROM `%sqamera_product_link` '
            . 'WHERE `id_shop` = %d AND `id_product` = %d',
            $this->tablePrefix,
            $idShop,
            $idProduct
        );
        $probe = $this->db->getRow($probeSql);
        if (!is_array($probe)) {
            return false;
        }

        $now = $this->escape($this->nowUtc());
        $sql = sprintf(
            'UPDATE `%sqamera_product_link` '
            . "SET `last_synced_at` = '%s', `updated_at` = '%s' "
            . 'WHERE `id_shop` = %d AND `id_product` = %d;',
            $this->tablePrefix,
            $now,
            $now,
            $idShop,
            $idProduct
        );

        if (!$this->db->execute($sql)) {
            throw new QameraDbException('product_link heartbeat failed');
        }

        return true;
    }

    private function nowUtc(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    private function escape(string $value): string
    {
        return $this->db->escape($value, true, true);
    }
}
