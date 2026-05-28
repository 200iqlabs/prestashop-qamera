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
        // Capture once — a second-boundary crossing between two calls
        // would leave `last_synced_at` and `updated_at` 1s apart on the
        // same row, complicating debugging without any operational gain.
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

        return (int) $this->db->Affected_Rows() >= 1;
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
