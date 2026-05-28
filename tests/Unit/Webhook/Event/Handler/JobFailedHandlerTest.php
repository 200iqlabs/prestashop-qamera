<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Webhook\Event\Handler;

use PHPUnit\Framework\TestCase;
use QameraAi\Module\Tests\Support\FakePackshotJobUpdater;
use QameraAi\Module\Tests\Support\FakePackshotLinkUpdater;
use QameraAi\Module\Tests\Support\FakeProductLinkHeartbeat;
use QameraAi\Module\Tests\Support\SpyLogger;
use QameraAi\Module\Webhook\Event\Handler\JobFailedHandler;
use QameraAi\Module\Webhook\Event\WebhookEvent;

final class JobFailedHandlerTest extends TestCase
{
    private FakePackshotLinkUpdater $packshot;
    private FakeProductLinkHeartbeat $heartbeat;
    private SpyLogger $logger;
    private FakePackshotJobUpdater $packshotJob;
    private JobFailedHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->packshot = new FakePackshotLinkUpdater();
        $this->heartbeat = new FakeProductLinkHeartbeat();
        $this->logger = new SpyLogger();
        $this->packshotJob = new FakePackshotJobUpdater();
        $this->handler = new JobFailedHandler(
            $this->packshot,
            $this->heartbeat,
            $this->logger,
            $this->packshotJob
        );
    }

    public function testFailedEventPopulatesLastErrorMessageAndFlipsStatus(): void
    {
        $this->heartbeat->nextReturns = true;

        $this->handler->handle($this->event([
            'external_ref' => 'ps:1:42:image:7',
            'packshot_id' => 'packshot-uuid',
            'job_id' => 'job-uuid',
            'error_message' => 'upstream_validation_failed',
        ]));

        self::assertCount(1, $this->packshot->upserts);
        $row = $this->packshot->upserts[0];
        self::assertSame('failed', $row['status']);
        self::assertSame('upstream_validation_failed', $row['last_error_message']);
    }

    public function testOversizedErrorMessageIsTruncatedToTextCapacity(): void
    {
        $this->heartbeat->nextReturns = true;
        $oversize = str_repeat('A', 70000);

        $this->handler->handle($this->event([
            'external_ref' => 'ps:1:42:image:7',
            'packshot_id' => 'packshot-uuid',
            'error_message' => $oversize,
        ]));

        $row = $this->packshot->upserts[0];
        self::assertLessThanOrEqual(65535, strlen((string) $row['last_error_message']));
    }

    public function testMissingErrorMessagePersistsAsNull(): void
    {
        $this->heartbeat->nextReturns = true;

        $this->handler->handle($this->event([
            'external_ref' => 'ps:1:42:image:7',
            'packshot_id' => 'packshot-uuid',
            // error_message omitted
        ]));

        self::assertNull($this->packshot->upserts[0]['last_error_message']);
    }

    public function testProductHeartbeatStillBumpedOnFailure(): void
    {
        $this->heartbeat->nextReturns = true;

        $this->handler->handle($this->event([
            'external_ref' => 'ps:1:42:image:7',
            'packshot_id' => 'packshot-uuid',
            'error_message' => 'boom',
        ]));

        self::assertCount(1, $this->heartbeat->touches);
        // The handler must never write product_link.status — that's owned
        // by Phase 3. The heartbeat fake records only the touch call; the
        // unit test for ProductLinkHeartbeat itself proves the UPDATE
        // statement does not include `status` in its SET clause.
    }

    public function testUnknownProductSkipsUpsert(): void
    {
        $this->heartbeat->nextReturns = false;

        $this->handler->handle($this->event([
            'external_ref' => 'ps:99:42:image:7',
            'packshot_id' => 'packshot-uuid',
            'error_message' => 'doesnt-matter',
        ]));

        self::assertCount(0, $this->packshot->upserts);
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
