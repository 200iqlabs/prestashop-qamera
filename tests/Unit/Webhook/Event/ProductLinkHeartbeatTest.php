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
        $this->db->affectedRowsScript = [1];

        self::assertTrue($this->heartbeat->touch(1, 42));
    }

    public function testRowAbsentReturnsFalse(): void
    {
        $this->db->affectedRowsScript = [0];

        self::assertFalse($this->heartbeat->touch(1, 42));
    }

    public function testDbFailureThrowsQameraDbException(): void
    {
        $this->db->failNextExecute = true;

        $this->expectException(QameraDbException::class);
        $this->heartbeat->touch(1, 42);
    }

    public function testUpdateTouchesOnlyLastSyncedAtAndUpdatedAt(): void
    {
        $this->db->affectedRowsScript = [1];
        $this->heartbeat->touch(1, 42);

        $sql = $this->db->executed[0];
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
        $this->db->affectedRowsScript = [1];
        $this->heartbeat->touch(7, 13);

        $sql = $this->db->executed[0];
        self::assertMatchesRegularExpression('/WHERE `id_shop` = 7 AND `id_product` = 13/', $sql);
    }
}
