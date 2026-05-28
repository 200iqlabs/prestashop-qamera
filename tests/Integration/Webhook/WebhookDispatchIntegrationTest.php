<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Integration\Webhook;

use PHPUnit\Framework\TestCase;

/**
 * Wire-faithful smoke covering HMAC-signed body → controller → DB row
 * updates → 200 ACK. Requires a booted PS core (Db / pSQL / Configuration
 * globals) and a MySQL/MariaDB instance — skipped under the default
 * unit-only runner.
 *
 * Manual coverage of the same path lives in tasks.md §10 (operator-driven
 * smoke against the live container).
 *
 * @group integration
 * @requires extension pdo_mysql
 */
final class WebhookDispatchIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('_PS_VERSION_')) {
            self::markTestSkipped(
                'PrestaShop core not bootstrapped - webhook dispatch integration test requires a real PS install.'
            );
        }
    }

    public function testJobCompletedDeliveryUpsertsPackshotAndBumpsHeartbeat(): void
    {
        self::markTestIncomplete(
            'Wire to PS bootstrap fixture (parent docker compose). '
            . 'Manual coverage via tasks.md section 10 (smoke checklist).'
        );
    }

    public function testJobFailedDeliveryPopulatesErrorMessage(): void
    {
        self::markTestIncomplete('Wire to PS bootstrap fixture.');
    }

    public function testDuplicateDeliveryInvokesDispatchExactlyOnce(): void
    {
        self::markTestIncomplete('Wire to PS bootstrap fixture.');
    }
}
