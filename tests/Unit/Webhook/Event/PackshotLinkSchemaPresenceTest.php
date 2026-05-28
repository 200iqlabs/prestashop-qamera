<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Webhook\Event;

use PHPUnit\Framework\TestCase;

/**
 * Source-level guard for the Phase-4.2 packshot_link schema extension.
 *
 * The end-to-end ALTER round-trip lives in the integration suite (see
 * tests/Integration/Install/SchemaUpgradeTest.php — markTestIncomplete
 * until the PS bootstrap fixture lands), so this test asserts the
 * Installer source contains the SQL that the spec requires: the wider
 * status ENUM, the new columns, the UNIQUE index on qamera_packshot_id,
 * and the idempotent INFORMATION_SCHEMA-guarded upgrade method.
 */
final class PackshotLinkSchemaPresenceTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->source = (string) file_get_contents(__DIR__ . '/../../../../src/Install/Installer.php');
    }

    public function testCreateTableHasWidenedStatusEnum(): void
    {
        self::assertStringContainsString(
            "ENUM('pending','ready','failed','cancelled','archived') NOT NULL DEFAULT 'pending'",
            $this->source
        );
    }

    public function testCreateTableHasNewBookkeepingColumns(): void
    {
        self::assertStringContainsString('`last_error_message` TEXT NULL', $this->source);
        self::assertStringContainsString('`last_synced_at` DATETIME NULL', $this->source);
        self::assertMatchesRegularExpression(
            '/qamera_packshot_link[\s\S]*?`updated_at` DATETIME NOT NULL/',
            $this->source
        );
    }

    public function testCreateTableHasUniqueIndexOnQameraPackshotId(): void
    {
        self::assertStringContainsString(
            'UNIQUE KEY `qamera_packshot_link_qamera_packshot_id` (`qamera_packshot_id`)',
            $this->source
        );
    }

    public function testMigratePackshotLinkSchemaIsIdempotentAndProbeGuarded(): void
    {
        self::assertStringContainsString('private function migratePackshotLinkSchema', $this->source);
        // Both probes (columns + indexes) must consult INFORMATION_SCHEMA
        // so the upgrade path is a no-op on already-migrated tables.
        self::assertStringContainsString('INFORMATION_SCHEMA.COLUMNS', $this->source);
        self::assertStringContainsString('INFORMATION_SCHEMA.STATISTICS', $this->source);
    }

    public function testEnumWideningIsAdditive(): void
    {
        // The MODIFY COLUMN statement must carry the full superset of values,
        // not a narrowing — Phase-3+ pre-existing rows in `pending`/`ready`/
        // `archived` must survive untouched.
        self::assertMatchesRegularExpression(
            "/MODIFY COLUMN `status`[\s\S]*?ENUM\\('pending','ready','failed','cancelled','archived'\\)/",
            $this->source
        );
    }

    public function testInstallerCallsPackshotMigration(): void
    {
        self::assertStringContainsString('$this->migratePackshotLinkSchema(', $this->source);
    }

    public function testDropSchemaStillTearsDownPackshotTable(): void
    {
        self::assertMatchesRegularExpression(
            '/DROP TABLE IF EXISTS[^;]*qamera_packshot_link/',
            $this->source
        );
    }
}
