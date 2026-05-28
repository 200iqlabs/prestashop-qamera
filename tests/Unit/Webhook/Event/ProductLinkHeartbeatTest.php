<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Webhook\Event;

use PHPUnit\Framework\TestCase;
use QameraAi\Module\Tests\Support\RecordingDb;
use QameraAi\Module\Webhook\Event\ProductLinkHeartbeat;
use QameraAi\Module\Webhook\Event\QameraDbException;

final class ProductLinkHeartbeatTest extends TestCase
{
    private RecordingDb $db;
    private ProductLinkHeartbeat $heartbeat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new RecordingDb();
        $this->heartbeat = new ProductLinkHeartbeat($this->db, 'ps_');
    }

    public function testRowPresentReturnsTrue(): void
    {
        $this->db->getRowScript = [['1' => '1']];
        $this->db->affectedRowsScript = [1];

        self::assertTrue($this->heartbeat->touch(1, 42));
    }

    public function testRowAbsentReturnsFalse(): void
    {
        // SELECT probe returns false → no UPDATE issued, returns false.
        $this->db->getRowScript = [false];

        self::assertFalse($this->heartbeat->touch(1, 42));
        // Critical: no UPDATE statement was executed.
        self::assertCount(1, $this->db->executed);
        self::assertStringStartsWith('SELECT 1 FROM `ps_qamera_product_link`', $this->db->executed[0]);
    }

    public function testIdempotentReDeliverySameSecondStillReturnsTrue(): void
    {
        // Regression for the silent-skip bug: when the row exists but the
        // UPDATE matches zero affected rows (because last_synced_at and
        // updated_at already carry the current second's value), the
        // heartbeat must still return true — otherwise the handler would
        // incorrectly log "unknown_product_link" and skip the packshot
        // upsert for a perfectly valid re-delivery.
        $this->db->getRowScript = [['1' => '1']];
        $this->db->affectedRowsScript = [0];

        self::assertTrue($this->heartbeat->touch(1, 42));
    }

    public function testDbFailureOnUpdateThrowsQameraDbException(): void
    {
        $this->db->getRowScript = [['1' => '1']];
        $this->db->failNextExecute = true;

        $this->expectException(QameraDbException::class);
        $this->heartbeat->touch(1, 42);
    }

    public function testUpdateTouchesOnlyLastSyncedAtAndUpdatedAt(): void
    {
        $this->db->getRowScript = [['1' => '1']];
        $this->db->affectedRowsScript = [1];
        $this->heartbeat->touch(1, 42);

        // The UPDATE is the SECOND statement; the SELECT probe is the first.
        $sql = $this->db->executed[1];
        self::assertStringContainsString('UPDATE `ps_qamera_product_link`', $sql);
        self::assertStringContainsString('SET `last_synced_at`', $sql);
        self::assertStringContainsString('`updated_at`', $sql);
        // Phase-3-owned columns must NOT appear in the SET clause.
        self::assertStringNotContainsString('`status`', $sql);
        self::assertStringNotContainsString('`qamera_product_id`', $sql);
        self::assertStringNotContainsString('`last_error_message`', $sql);
    }

    public function testWhereClauseScopesByShopAndProduct(): void
    {
        $this->db->getRowScript = [['1' => '1']];
        $this->db->affectedRowsScript = [1];
        $this->heartbeat->touch(7, 13);

        $sql = $this->db->executed[1];
        self::assertMatchesRegularExpression('/WHERE `id_shop` = 7 AND `id_product` = 13/', $sql);
    }

    public function testProbeQueryDoesNotCarryExplicitLimit(): void
    {
        // Regression: PrestaShop's Db::getRow() auto-appends `LIMIT 1` to
        // every query. A SELECT that already carries `LIMIT 1` produces a
        // `LIMIT 1 LIMIT 1` syntax error at MySQL. The dispatcher then
        // catches the PrestaShopException, logs `dispatch_handler_failed`
        // with class name only, and the handler silently skips the
        // packshot write — observable only via a live integration smoke.
        // Source-grep this assertion to prevent the regression because
        // the Db test double doesn't simulate PS's getRow contract.
        $this->db->getRowScript = [['1' => '1']];
        $this->db->affectedRowsScript = [1];
        $this->heartbeat->touch(1, 42);

        $probeSql = $this->db->executed[0];
        self::assertStringContainsString('SELECT 1 FROM `ps_qamera_product_link`', $probeSql);
        // Case-insensitive: SQL keywords are case-insensitive, so a
        // future reintroduction as `limit 1` would still collide with
        // PS's appended LIMIT and break the same way.
        self::assertDoesNotMatchRegularExpression('/\bLIMIT\b/i', $probeSql);
    }
}
