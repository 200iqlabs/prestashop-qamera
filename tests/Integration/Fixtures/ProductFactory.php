<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Integration\Fixtures;

use Configuration;
use Product;
use RuntimeException;

/**
 * Creates real `Product` rows tagged with the reserved `TEST-`
 * reference prefix so per-test teardown and the suite-wide bootstrap
 * sweep (`cleanupTestFixtures` / `cleanupTestFixturesByMarker`) can
 * find them by `reference LIKE 'TEST-{marker}-%'`.
 *
 * Defaults are deliberately minimal — tests that need additional
 * fields (categories, combinations, stock) set them on the returned
 * instance and call `Product::save()` themselves.
 */
final class ProductFactory
{
    /**
     * @param string $marker per-test marker from `IntegrationTestCase::$marker`
     * @param string $suffix differentiates multiple products inside the same test
     */
    public static function createProduct(
        int $idShop,
        string $marker,
        string $suffix = '001',
        string $name = 'TEST Widget',
        ?string $sku = null
    ): Product {
        $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
        if ($idLang <= 0) {
            $idLang = 1;
        }
        $idCategory = (int) Configuration::get('PS_HOME_CATEGORY');
        if ($idCategory <= 0) {
            $idCategory = 2;
        }

        $product = new Product();
        $product->id_shop_default = $idShop;
        $product->id_category_default = $idCategory;
        $product->name = [$idLang => $name];
        $product->link_rewrite = [$idLang => 'test-widget-' . $marker . '-' . $suffix];
        $product->price = 9.99;
        $product->active = true;
        $product->reference = sprintf('TEST-%s-%s', $marker, $suffix);
        if ($sku !== null) {
            $product->ean13 = $sku;
        }

        if (!$product->add()) {
            throw new RuntimeException(
                sprintf(
                    'ProductFactory: failed to add product with reference %s.',
                    $product->reference
                )
            );
        }

        return $product;
    }
}
