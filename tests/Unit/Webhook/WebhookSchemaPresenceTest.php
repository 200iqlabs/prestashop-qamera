<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Webhook;

use PHPUnit\Framework\TestCase;

/**
 * Source-level guard: the Installer must emit the webhook delivery
 * schema in both install (CREATE) and uninstall (DROP) paths, and an
 * upgrade script must exist for already-installed deployments.
 *
 * A full end-to-end install/uninstall round-trip lives in
 * tests/Integration/Install/SchemaUpgradeTest.php (markTestIncomplete
 * until the PS bootstrap fixture lands).
 */
final class WebhookSchemaPresenceTest extends TestCase
{
    public function testInstallerCreatesWebhookDeliveryTable(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../src/Install/Installer.php');

        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS', $source);
        self::assertStringContainsString('qamera_webhook_delivery', $source);
        self::assertStringContainsString('`delivery_id` VARCHAR(64) NOT NULL', $source);
        self::assertStringContainsString('PRIMARY KEY (`delivery_id`)', $source);
        self::assertStringContainsString('`qamera_webhook_event_type`', $source);
        self::assertStringContainsString("ENUM('accepted','duplicate','rejected')", $source);
    }

    public function testInstallerDropsWebhookDeliveryTable(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../src/Install/Installer.php');

        self::assertMatchesRegularExpression(
            '/DROP TABLE IF EXISTS[^;]*qamera_webhook_delivery/',
            $source
        );
    }

    public function testUpgradeScriptExistsForPhaseFourOne(): void
    {
        $upgrade = (string) file_get_contents(__DIR__ . '/../../../upgrade/upgrade-1.3.0.php');

        self::assertStringContainsString('upgrade_module_1_3_0', $upgrade);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS', $upgrade);
        self::assertStringContainsString('qamera_webhook_delivery', $upgrade);
    }
}
