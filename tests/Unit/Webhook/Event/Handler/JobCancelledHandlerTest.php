<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Webhook\Event\Handler;

use PHPUnit\Framework\TestCase;
use QameraAi\Module\Tests\Support\FakePackshotLinkUpdater;
use QameraAi\Module\Tests\Support\FakeProductLinkHeartbeat;
use QameraAi\Module\Tests\Support\SpyLogger;
use QameraAi\Module\Webhook\Event\Handler\JobCancelledHandler;
use QameraAi\Module\Webhook\Event\WebhookEvent;

final class JobCancelledHandlerTest extends TestCase
{
    private FakePackshotLinkUpdater $packshot;
    private FakeProductLinkHeartbeat $heartbeat;
    private SpyLogger $logger;
    private JobCancelledHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->packshot = new FakePackshotLinkUpdater();
        $this->heartbeat = new FakeProductLinkHeartbeat();
        $this->logger = new SpyLogger();
        $this->handler = new JobCancelledHandler($this->packshot, $this->heartbeat, $this->logger);
    }

    public function testCancelledEventOverwritesReadyRow(): void
    {
        $this->heartbeat->nextReturns = true;
        // Simulate an existing row → update path.
        $this->packshot->nextReturnsInsert = false;

        $this->handler->handle($this->event([
            'external_ref' => 'ps:1:42:image:7',
            'packshot_id' => 'packshot-uuid',
        ]));

        $row = $this->packshot->upserts[0];
        self::assertSame('cancelled', $row['status']);
        self::assertNull($row['last_error_message']);
    }

    public function testCancelledEventForUnknownPackshotInsertsCancelledRow(): void
    {
        $this->heartbeat->nextReturns = true;
        // No existing row → insert path.
        $this->packshot->nextReturnsInsert = true;

        $this->handler->handle($this->event([
            'external_ref' => 'ps:1:42:image:7',
            'packshot_id' => 'fresh-uuid',
        ]));

        self::assertCount(1, $this->packshot->upserts);
        self::assertSame('cancelled', $this->packshot->upserts[0]['status']);
    }

    public function testMissingPackshotIdLogsError(): void
    {
        $this->handler->handle($this->event(['external_ref' => 'ps:1:42:image:7']));

        self::assertNotEmpty($this->logger->entriesAtLevel('error'));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function event(array $payload): WebhookEvent
    {
        return new WebhookEvent('job.cancelled', 'D-c', null, $payload);
    }
}
