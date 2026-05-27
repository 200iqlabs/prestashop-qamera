<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Integration\Fixtures;

use Db;
use Product;
use QameraAi\Module\Sync\ProductRefBuilder;
use RuntimeException;

/**
 * Seeds a `ps_qamera_product_link` row mirroring the state that the
 * Phase-2 `actionProductSave` hook would have written for the given
 * product. Used by Phase-3 integration tests so they can exercise
 * `ProductImageSyncService::syncOnImageAdded` without driving the
 * upstream `actionProductSave` path first.
 */
final class BookkeepingFactory
{
    /**
     * @param 'pending'|'registered'|'error' $status
     */
    public static function seedRow(
        Product $product,
        int $idShop,
        string $status = 'pending',
        ?string $qameraProductId = null
    ): void {
        $db = Db::getInstance();
        $prefix = _DB_PREFIX_;

        $refBuilder = new ProductRefBuilder();
        $qameraRef = $refBuilder->build($idShop, (int) $product->id);

        $idProduct = (int) $product->id;
        $displayName = $product->name;
        if (is_array($displayName)) {
            $displayName = (string) reset($displayName);
        } else {
            $displayName = (string) $displayName;
        }

        $sku = is_string($product->reference) ? $product->reference : '';
        $description = '';

        $statusSql = $db->escape($status, false, true);
        $refSql = $db->escape($qameraRef, false, true);
        $displayNameSql = $db->escape($displayName, false, true);
        $skuSql = $db->escape($sku, false, true);
        $descriptionSql = $db->escape($description, false, true);

        $qameraProductIdSql = $qameraProductId !== null
            ? "'" . $db->escape($qameraProductId, false, true) . "'"
            : 'NULL';

        $sql = 'INSERT INTO `' . $prefix . 'qamera_product_link` '
            . '(`id_product`, `id_shop`, `qamera_product_ref`, `qamera_product_id`, '
            . '`status`, `display_name_snapshot`, `sku_snapshot`, `description_snapshot`, '
            . '`created_at`, `updated_at`) VALUES '
            . '(' . $idProduct . ', ' . $idShop . ', '
            . "'" . $refSql . "', " . $qameraProductIdSql . ", "
            . "'" . $statusSql . "', '" . $displayNameSql . "', "
            . "'" . $skuSql . "', '" . $descriptionSql . "', NOW(), NOW())";

        if (!$db->execute($sql)) {
            throw new RuntimeException(
                sprintf(
                    'BookkeepingFactory: failed to insert row for id_product=%d.',
                    $idProduct
                )
            );
        }
    }
}
