<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Webhook\Event\Handler;

use PHPUnit\Framework\TestCase;
use QameraAi\Module\Tests\Support\FakePackshotJobUpdater;
use QameraAi\Module\Tests\Support\FakeProductLinkHeartbeat;
use QameraAi\Module\Tests\Support\SpyLogger;
use QameraAi\Module\Webhook\Event\Handler\JobCancelledHandler;
use QameraAi\Module\Webhook\Event\WebhookEvent;

final class JobCancelledHandlerTest extends TestCase
{
    private FakeProductLinkHeartbeat $heartbeat;
    private SpyLogger $logger;
    private FakePackshotJobUpdater $packshotJob;
    private JobCancelledHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->heartbeat = new FakeProductLinkHeartbeat();
        $this->logger = new SpyLogger();
        $this->packshotJob = new FakePackshotJobUpdater();
        $this->handler = new JobCancelledHandler($this->heartbeat, $this->logger, $this->packshotJob);
    }

    public function testCancelledEventMirrorsCancelledStatus(): void
    {
        $this->heartbeat->nextReturns = true;

        $this->handler->handle($this->event([
            'job' => ['product_ref' => 'ps:1:42', 'id' => 'job-uuid'],
        ]));

        self::assertSame([['idShop' => 1, 'idProduct' => 42]], $this->heartbeat->touches);
        $u = $this->packshotJob->upserts[0];
        self::assertSame('job.cancelled', $u['event_type']);
        self::assertSame('job-uuid', $u['qamera_job_id']);
        self::assertNull($u['output_url']);
        self::assertNull($u['last_error_message']);
    }

    public function testMissingJobIdLogsError(): void
    {
        $this->handler->handle($this->event(['job' => ['product_ref' => 'ps:1:42']]));

        $errors = $this->logger->entriesAtLevel('error');
        self::assertSame('payload_missing_field', $errors[0]['message']);
        self::assertSame('job.id', $errors[0]['context']['field']);
    }

    public function testUnknownProductSkipsMirror(): void
    {
        $this->heartbeat->nextReturns = false;

        $this->handler->handle($this->event([
            'job' => ['product_ref' => 'ps:99:42', 'id' => 'job-uuid'],
        ]));

        self::assertCount(0, $this->packshotJob->upserts);
        self::assertNotEmpty($this->logger->entriesAtLevel('warning'));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function event(array $payload): WebhookEvent
    {
        return new WebhookEvent('job.cancelled', 'D-c', null, $payload);
    }
}
