<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Webhook\Event\Handler;

use PHPUnit\Framework\TestCase;
use QameraAi\Module\Tests\Support\FakePackshotJobUpdater;
use QameraAi\Module\Tests\Support\FakeProductLinkHeartbeat;
use QameraAi\Module\Tests\Support\SpyLogger;
use QameraAi\Module\Webhook\Event\Handler\JobFailedHandler;
use QameraAi\Module\Webhook\Event\WebhookEvent;

final class JobFailedHandlerTest extends TestCase
{
    private FakeProductLinkHeartbeat $heartbeat;
    private SpyLogger $logger;
    private FakePackshotJobUpdater $packshotJob;
    private JobFailedHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->heartbeat = new FakeProductLinkHeartbeat();
        $this->logger = new SpyLogger();
        $this->packshotJob = new FakePackshotJobUpdater();
        $this->handler = new JobFailedHandler($this->heartbeat, $this->logger, $this->packshotJob);
    }

    public function testFailedEventMirrorsErrorMessageFromJobError(): void
    {
        $this->heartbeat->nextReturns = true;

        $this->handler->handle($this->event([
            'job' => [
                'product_ref' => 'ps:1:42',
                'id' => 'job-uuid',
                'error' => [
                    'code' => 'generation_failed',
                    'message_i18n' => ['en' => 'No source upload found'],
                    'retryable' => false,
                ],
            ],
        ]));

        self::assertSame([['idShop' => 1, 'idProduct' => 42]], $this->heartbeat->touches);
        $u = $this->packshotJob->upserts[0];
        self::assertSame('job.failed', $u['event_type']);
        self::assertSame('No source upload found', $u['last_error_message']);
        self::assertNull($u['output_url']);
    }

    public function testFailedEventWithNoErrorObjectMirrorsNullMessage(): void
    {
        $this->heartbeat->nextReturns = true;

        $this->handler->handle($this->event([
            'job' => ['product_ref' => 'ps:1:42', 'id' => 'job-uuid', 'error' => null],
        ]));

        self::assertNull($this->packshotJob->upserts[0]['last_error_message']);
    }

    public function testProductHeartbeatStillBumpedOnFailure(): void
    {
        $this->heartbeat->nextReturns = true;

        $this->handler->handle($this->event([
            'job' => ['product_ref' => 'ps:1:42', 'id' => 'job-uuid'],
        ]));

        self::assertCount(1, $this->heartbeat->touches);
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
        return new WebhookEvent('job.failed', 'D-f', null, $payload);
    }
}
