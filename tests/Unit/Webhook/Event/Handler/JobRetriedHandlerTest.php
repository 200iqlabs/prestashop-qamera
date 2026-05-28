<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Webhook\Event\Handler;

use PHPUnit\Framework\TestCase;
use QameraAi\Module\Tests\Support\FakeProductLinkHeartbeat;
use QameraAi\Module\Tests\Support\RecordingDb;
use QameraAi\Module\Tests\Support\SpyLogger;
use QameraAi\Module\Webhook\Event\Handler\JobRetriedHandler;
use QameraAi\Module\Webhook\Event\QameraDbException;
use QameraAi\Module\Webhook\Event\WebhookEvent;

final class JobRetriedHandlerTest extends TestCase
{
    private RecordingDb $db;
    private FakeProductLinkHeartbeat $heartbeat;
    private SpyLogger $logger;
    private JobRetriedHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new RecordingDb();
        $this->heartbeat = new FakeProductLinkHeartbeat();
        $this->logger = new SpyLogger();
        $this->handler = new JobRetriedHandler($this->db, 'ps_', $this->heartbeat, $this->logger);
    }

    public function testRetriedEventBumpsLastSyncedAtWithoutChangingStatus(): void
    {
        $this->heartbeat->nextReturns = true;
        $this->db->affectedRowsScript = [1];

        $this->handler->handle($this->event([
            'external_ref' => 'ps:1:42:image:7',
            'packshot_id' => 'packshot-uuid',
        ]));

        self::assertCount(1, $this->heartbeat->touches);
        self::assertNotEmpty($this->db->executed);
        $sql = $this->db->lastExecuted();
        self::assertStringContainsString('UPDATE `ps_qamera_packshot_link`', $sql);
        self::assertStringContainsString('SET `last_synced_at`', $sql);
        self::assertStringContainsString('`updated_at`', $sql);
        // The packshot UPDATE must NOT touch `status` — upstream is still
        // working, the terminal event will own the status transition.
        self::assertStringNotContainsString('`status`', $sql);
        // Scoped by qamera_packshot_id (UPSERT-equivalent UPDATE).
        self::assertStringContainsString("WHERE `qamera_packshot_id` = 'packshot-uuid'", $sql);
    }

    public function testRetriedForUnknownPackshotIsNoOpForPackshotTable(): void
    {
        $this->heartbeat->nextReturns = true;
        // No matching row → affected_rows == 0, no error.
        $this->db->affectedRowsScript = [0];

        $this->handler->handle($this->event([
            'external_ref' => 'ps:1:42:image:7',
            'packshot_id' => 'never-seen-uuid',
        ]));

        // The UPDATE statement still ran (it's a no-op at MySQL level),
        // but no error is logged and no exception propagates.
        self::assertEmpty($this->logger->entriesAtLevel('error'));
    }

    public function testUnknownProductSkipsPackshotUpdate(): void
    {
        $this->heartbeat->nextReturns = false;

        $this->handler->handle($this->event([
            'external_ref' => 'ps:99:42:image:7',
            'packshot_id' => 'packshot-uuid',
        ]));

        // The packshot UPDATE was never even attempted.
        self::assertEmpty($this->db->executed);
        self::assertNotEmpty($this->logger->entriesAtLevel('warning'));
    }

    public function testDbFailureDuringPackshotUpdateThrows(): void
    {
        $this->heartbeat->nextReturns = true;
        $this->db->failNextExecute = true;

        $this->expectException(QameraDbException::class);
        $this->handler->handle($this->event([
            'external_ref' => 'ps:1:42:image:7',
            'packshot_id' => 'packshot-uuid',
        ]));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function event(array $payload): WebhookEvent
    {
        return new WebhookEvent('job.retried', 'D-r', null, $payload);
    }
}
