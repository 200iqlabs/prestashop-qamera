<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Webhook\Event\Handler;

use PHPUnit\Framework\TestCase;
use QameraAi\Module\Tests\Support\FakePackshotJobUpdater;
use QameraAi\Module\Tests\Support\FakeProductLinkHeartbeat;
use QameraAi\Module\Tests\Support\SpyLogger;
use QameraAi\Module\Webhook\Event\Handler\JobCompletedHandler;
use QameraAi\Module\Webhook\Event\QameraDbException;
use QameraAi\Module\Webhook\Event\WebhookEvent;

final class JobCompletedHandlerTest extends TestCase
{
    private FakeProductLinkHeartbeat $heartbeat;
    private SpyLogger $logger;
    private FakePackshotJobUpdater $packshotJob;
    private JobCompletedHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->heartbeat = new FakeProductLinkHeartbeat();
        $this->logger = new SpyLogger();
        $this->packshotJob = new FakePackshotJobUpdater();
        $this->handler = new JobCompletedHandler($this->heartbeat, $this->logger, $this->packshotJob);
    }

    public function testHappyPathBumpsHeartbeatAndMirrorsJob(): void
    {
        $this->heartbeat->nextReturns = true;

        $this->handler->handle($this->event([
            'job' => ['product_ref' => 'ps:1:42', 'id' => 'job-uuid', 'order_id' => 'ord-1'],
            'outputs' => [['url' => 'https://storage.example/o.png']],
        ]));

        self::assertSame([['idShop' => 1, 'idProduct' => 42]], $this->heartbeat->touches);
        self::assertCount(1, $this->packshotJob->upserts);
        $u = $this->packshotJob->upserts[0];
        self::assertSame('job.completed', $u['event_type']);
        self::assertSame('job-uuid', $u['qamera_job_id']);
        self::assertSame('https://storage.example/o.png', $u['output_url']);
        self::assertSame('ps:1:42', $u['product_ref']);
        self::assertSame('ord-1', $u['order_id']);
        self::assertNull($u['last_error_message']);
    }

    public function testUnknownProductLogsWarningAndSkipsMirror(): void
    {
        $this->heartbeat->nextReturns = false;

        $this->handler->handle($this->event([
            'job' => ['product_ref' => 'ps:99:42', 'id' => 'job-uuid'],
        ]));

        self::assertCount(0, $this->packshotJob->upserts);
        $warnings = $this->logger->entriesAtLevel('warning');
        self::assertSame('unknown_product_link', $warnings[0]['message']);
        self::assertSame('ps:99:42', $warnings[0]['context']['product_ref']);
    }

    public function testMalformedProductRefLogsWarningAndSkips(): void
    {
        $this->handler->handle($this->event([
            'job' => ['product_ref' => 'not-a-ref', 'id' => 'job-uuid'],
        ]));

        self::assertCount(0, $this->heartbeat->touches);
        self::assertCount(0, $this->packshotJob->upserts);
        self::assertSame('malformed_product_ref', $this->logger->entriesAtLevel('warning')[0]['message']);
    }

    public function testMissingProductRefLogsErrorAndSkips(): void
    {
        $this->handler->handle($this->event(['job' => ['id' => 'job-uuid']]));

        $errors = $this->logger->entriesAtLevel('error');
        self::assertSame('payload_missing_field', $errors[0]['message']);
        self::assertSame('job.product_ref', $errors[0]['context']['field']);
        self::assertCount(0, $this->packshotJob->upserts);
    }

    public function testMissingJobIdLogsErrorAndSkips(): void
    {
        $this->handler->handle($this->event(['job' => ['product_ref' => 'ps:1:42']]));

        $errors = $this->logger->entriesAtLevel('error');
        self::assertSame('payload_missing_field', $errors[0]['message']);
        self::assertSame('job.id', $errors[0]['context']['field']);
        self::assertCount(0, $this->heartbeat->touches);
    }

    public function testDbExceptionDuringMirrorPropagatesToDispatcher(): void
    {
        $this->heartbeat->nextReturns = true;
        $this->packshotJob->throwOnNext = new QameraDbException('boom');

        $this->expectException(QameraDbException::class);
        $this->handler->handle($this->event([
            'job' => ['product_ref' => 'ps:1:42', 'id' => 'job-uuid'],
        ]));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function event(array $payload): WebhookEvent
    {
        return new WebhookEvent('job.completed', 'D1', null, $payload);
    }
}
