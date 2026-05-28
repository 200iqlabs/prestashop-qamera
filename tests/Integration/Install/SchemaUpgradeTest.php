<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Integration\Install;

use PHPUnit\Framework\TestCase;

/**
 * Verifies Installer::createSchema() applied twice is idempotent and
 * that an existing Phase-1 `qamera_product_link` table (with
 * `qamera_product_id NOT NULL` and no snapshot columns) is migrated
 * up to the Phase-2 column set. Also asserts the Phase-4.4
 * (add-analysis-status-surfacing) ALTER additions converge fresh
 * installs and upgrades onto an identical analysis-columns shape.
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

    /**
     * Phase 4.4 analysis-columns convergence. Asserts both paths produce
     * the same final shape on `ps_qamera_product_link`:
     *
     *  - Fresh install: `Installer::install()` → CREATE TABLE includes
     *    analysis_status / analysis_described_count / analysis_total_count
     *    / analysis_refreshed_at; migrateProductLinkSchema() is a no-op
     *    on those columns.
     *  - Upgrade install: a pre-4.4 `qamera_product_link` (built by an
     *    earlier `createSchema()` run before this commit) gets the four
     *    columns appended by `migrateProductLinkSchema()` or by the
     *    upgrade-1.4.0.php hook, with identical types + nullability.
     *
     * Re-running either path SHALL be a no-op (INFORMATION_SCHEMA guard).
     */
    public function testAnalysisColumnsConvergeAcrossFreshAndUpgradePaths(): void
    {
        self::markTestIncomplete(
            'Wire to PS bootstrap fixture (parent docker compose) to run end-to-end. '
            . 'Assertions to cover: column presence, ENUM signature on '
            . 'analysis_status, INT UNSIGNED nullability on the two count '
            . 'columns, DATETIME nullability on analysis_refreshed_at. '
            . 'Manual coverage via tasks.md section 9 (smoke checklist).'
        );
    }
}
