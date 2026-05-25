<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Integration\Sync;

use PHPUnit\Framework\TestCase;

/**
 * End-to-end hook flow covered against a booted PrestaShop test
 * instance (Db, Product, Configuration, PrestaShopLogger all live).
 * Skipped when PS core is not bootstrapped - wired via the parent
 * docker compose stack in CI.
 *
 * @group integration
 */
final class ProductSyncIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('_PS_VERSION_')) {
            self::markTestSkipped('PrestaShop core not bootstrapped.');
        }
    }

    public function testToggleOffNoRow(): void
    {
        self::markTestIncomplete('Wire to PS bootstrap fixture; manual coverage via tasks.md section 10.6.');
    }

    public function testToggleOnInsertsPendingRow(): void
    {
        self::markTestIncomplete('Wire to PS bootstrap fixture; manual coverage via tasks.md section 10.3-10.4.');
    }

    public function testUpdateRefreshesSnapshotWithoutStatusChange(): void
    {
        self::markTestIncomplete('Wire to PS bootstrap fixture; manual coverage via tasks.md section 10.5.');
    }

    public function testHookSwallowsDbFailure(): void
    {
        self::markTestIncomplete('Wire to PS bootstrap fixture; manual coverage via tasks.md section 10.7.');
    }
}
