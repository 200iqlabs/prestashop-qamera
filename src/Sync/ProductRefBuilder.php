<?php

declare(strict_types=1);

namespace QameraAi\Module\Sync;

use InvalidArgumentException;

/**
 * Builds the deterministic `qamera_product_ref` string stored on every
 * `qamera_product_link` row. Format: `ps:{id_shop}:{id_product}`. The
 * `ps:` prefix disambiguates PrestaShop refs from any other plugin
 * platform that might share an upstream installation in the future;
 * the (id_shop, id_product) pair makes the ref unique within a single
 * PrestaShop install (multistore aware).
 */
final class ProductRefBuilder
{
    public function build(int $idShop, int $idProduct): string
    {
        if ($idShop <= 0) {
            throw new InvalidArgumentException(
                'ProductRefBuilder: id_shop must be a positive integer.'
            );
        }
        if ($idProduct <= 0) {
            throw new InvalidArgumentException(
                'ProductRefBuilder: id_product must be a positive integer.'
            );
        }

        return sprintf('ps:%d:%d', $idShop, $idProduct);
    }
}
