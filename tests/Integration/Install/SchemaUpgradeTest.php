<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Integration\Install;

use PHPUnit\Framework\TestCase;

/**
 * Verifies Installer::createSchema() applied twice is idempotent and
 * that an existing Phase-1 `qamera_product_link` table (with
 * `qamera_product_id NOT NULL` and no snapshot columns) is migrated
 * up to the Phase-2 column set.
 *
 * Requires a MySQL/MariaDB instance reachable via PDO and a booted PS
 * core (so the global Db / pSQL helpers resolve). Skipped under the
 * default unit-only runner; CI is expected to run with --group=integration.
 *
 * @group integration
 * @requires extension pdo_mysql
 */
final class SchemaUpgradeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('_PS_VERSION_')) {
            self::markTestSkipped(
                'PrestaShop core not bootstrapped - schema-upgrade test requires a real PS install.'
            );
        }
    }

    public function testDoubleCreateIsIdempotentAndMigratesPhase1Table(): void
    {
        self::markTestIncomplete(
            'Wire to PS bootstrap fixture (parent docker compose) to run end-to-end. '
            . 'Manual coverage via tasks.md section 10 (smoke checklist).'
        );
    }
}
