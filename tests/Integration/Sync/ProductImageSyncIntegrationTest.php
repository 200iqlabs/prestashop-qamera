<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Integration\Sync;

use PHPUnit\Framework\TestCase;

/**
 * End-to-end image-sync flow against a booted PrestaShop test instance
 * (Db, Product, Image, Configuration, PrestaShopLogger all live; the
 * upstream HTTP is hit against a controlled fixture endpoint or a real
 * Qamera AI staging install). Skipped when PS core is not bootstrapped
 * — wired via the parent docker compose stack in CI; manual coverage
 * via the operator smoke checklist in tasks.md §11.
 *
 * @group integration
 */
final class ProductImageSyncIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('_PS_VERSION_')) {
            self::markTestSkipped('PrestaShop core not bootstrapped.');
        }
    }

    public function testRegistersPendingProductOnFirstImage(): void
    {
        self::markTestIncomplete(
            'Wire to PS bootstrap fixture; manual coverage via tasks.md §11.3-11.4.'
        );
    }

    public function testSubsequentImageOnRegisteredProductSkipsMetadata(): void
    {
        self::markTestIncomplete(
            'Wire to PS bootstrap fixture; manual coverage via tasks.md §11.5.'
        );
    }

    public function testErrorPathPersistsLastErrorMessage(): void
    {
        self::markTestIncomplete(
            'Wire to PS bootstrap fixture; manual coverage via tasks.md §11.6.'
        );
    }
}
