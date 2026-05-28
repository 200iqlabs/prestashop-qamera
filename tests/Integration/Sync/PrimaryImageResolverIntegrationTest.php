<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Integration\Sync;

use QameraAi\Module\Sync\PrimaryImageResolver;
use QameraAi\Module\Tests\Integration\Fixtures\ImageFactory;
use QameraAi\Module\Tests\Integration\Fixtures\ProductFactory;
use QameraAi\Module\Tests\Integration\IntegrationTestCase;

/**
 * Exercises `PrimaryImageResolver` against the real `Image` PS class —
 * proving the cover-image lookup and the `_PS_PRODUCT_IMG_DIR_`
 * filesystem layout match between resolver and storage.
 *
 * Covers Phase-3 smoke regression scenario 2 (`_PS_PRODUCT_IMG_DIR_`
 * typo class of bugs — see `integration-test-harness` spec §5).
 */
final class PrimaryImageResolverIntegrationTest extends IntegrationTestCase
{
    private const ID_SHOP = 1;

    public function testResolvesCoverImageFromRealPsImageClass(): void
    {
        $product = ProductFactory::createProduct(self::ID_SHOP, $this->marker, '010');
        $first = ImageFactory::attachImage($product);
        $second = ImageFactory::attachImage($product);

        // ImageFactory marks the first image as cover (no existing cover);
        // the second is non-cover. Resolver should return the first.
        $resolver = new PrimaryImageResolver();
        $resolved = $resolver->resolve((int) $product->id, (int) $second->id, 1);

        self::assertSame((int) $first->id, $resolved);

        // Smoke regression: the resolved id must correspond to a real
        // file at the path constructed from _PS_PRODUCT_IMG_DIR_ — the
        // typo bug (_PS_PROD_IMG_DIR_) would produce an unreadable path.
        $base = (string) constant('_PS_PRODUCT_IMG_DIR_');
        $folder = $base . $this->buildImageFolder((int) $first->id);
        $file = $folder . ((int) $first->id) . '.jpg';
        self::assertFileExists($file);
        self::assertGreaterThan(0, (int) filesize($file));
    }

    public function testFallsBackToHintWhenNoCover(): void
    {
        $product = ProductFactory::createProduct(self::ID_SHOP, $this->marker, '011');
        $image = ImageFactory::attachImage($product);

        // Clear cover flag — simulates "operator never set a cover".
        \Image::deleteCover((int) $product->id);

        $resolver = new PrimaryImageResolver();
        $resolved = $resolver->resolve((int) $product->id, (int) $image->id, 1);

        self::assertSame((int) $image->id, $resolved);
    }

    private function buildImageFolder(int $idImage): string
    {
        $chars = (string) $idImage;
        $folder = '';
        $len = strlen($chars);
        for ($i = 0; $i < $len; $i++) {
            $folder .= $chars[$i] . '/';
        }
        return $folder;
    }
}
