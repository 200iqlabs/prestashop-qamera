<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Webhook\Event\Handler;

use PHPUnit\Framework\TestCase;
use QameraAi\Module\Tests\Support\FakePackshotLinkUpdater;
use QameraAi\Module\Tests\Support\FakeProductLinkHeartbeat;
use QameraAi\Module\Tests\Support\SpyLogger;
use QameraAi\Module\Webhook\Event\Handler\JobCompletedHandler;
use QameraAi\Module\Webhook\Event\QameraDbException;
use QameraAi\Module\Webhook\Event\WebhookEvent;

final class JobCompletedHandlerTest extends TestCase
{
    private FakePackshotLinkUpdater $packshot;
    private FakeProductLinkHeartbeat $heartbeat;
    private SpyLogger $logger;
    private JobCompletedHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->packshot = new FakePackshotLinkUpdater();
        $this->heartbeat = new FakeProductLinkHeartbeat();
        $this->logger = new SpyLogger();
        $this->handler = new JobCompletedHandler($this->packshot, $this->heartbeat, $this->logger);
    }

    public function testHappyPathInsertsPackshotAndBumpsHeartbeat(): void
    {
        $this->heartbeat->nextReturns = true;

        $this->handler->handle($this->event([
            'external_ref' => 'ps:1:42:image:7',
            'packshot_id' => 'packshot-uuid',
            'job_id' => 'job-uuid',
        ]));

        self::assertCount(1, $this->heartbeat->touches);
        self::assertSame(['idShop' => 1, 'idProduct' => 42], $this->heartbeat->touches[0]);
        self::assertCount(1, $this->packshot->upserts);
        $row = $this->packshot->upserts[0];
        self::assertSame('packshot-uuid', $row['qamera_packshot_id']);
        self::assertSame('job-uuid', $row['qamera_job_id']);
        self::assertSame('ready', $row['status']);
        self::assertSame(1, $row['id_shop']);
        self::assertSame(42, $row['id_product']);
        self::assertNull($row['last_error_message']);
        self::assertSame('ps:1:42:packshot:packshot-uuid', $row['qamera_packshot_ref']);
        self::assertLessThanOrEqual(200, strlen($row['qamera_packshot_ref']));
    }

    public function testIdempotentRedeliveryStillUpserts(): void
    {
        $this->heartbeat->nextReturns = true;
        $this->packshot->nextReturnsInsert = false;

        $this->handler->handle($this->event([
            'external_ref' => 'ps:1:42:image:7',
            'packshot_id' => 'packshot-uuid',
        ]));

        // The upsert was still called — re-delivery semantics are owned by
        // the unique index inside PackshotLinkUpdater::upsertByPackshotId().
        self::assertCount(1, $this->packshot->upserts);
        self::assertEmpty($this->logger->entriesAtLevel('error'));
    }

    public function testUnknownProductLogsWarningAndSkipsUpsert(): void
    {
        $this->heartbeat->nextReturns = false;

        $this->handler->handle($this->event([
            'external_ref' => 'ps:99:42:image:7',
            'packshot_id' => 'packshot-uuid',
        ]));

        self::assertCount(0, $this->packshot->upserts);
        $warnings = $this->logger->entriesAtLevel('warning');
        self::assertNotEmpty($warnings);
        self::assertSame('unknown_product_link', $warnings[0]['message']);
        self::assertSame('ps:99:42:image:7', $warnings[0]['context']['external_ref']);
    }

    public function testMalformedExternalRefLogsWarningAndSkips(): void
    {
        $this->handler->handle($this->event([
            'external_ref' => 'not-a-ref',
            'packshot_id' => 'packshot-uuid',
        ]));

        self::assertCount(0, $this->heartbeat->touches);
        self::assertCount(0, $this->packshot->upserts);
        $warnings = $this->logger->entriesAtLevel('warning');
        self::assertNotEmpty($warnings);
        self::assertSame('malformed_external_ref', $warnings[0]['message']);
    }

    public function testMissingExternalRefLogsErrorAndSkips(): void
    {
        $this->handler->handle($this->event(['packshot_id' => 'packshot-uuid']));

        $errors = $this->logger->entriesAtLevel('error');
        self::assertNotEmpty($errors);
        self::assertSame('payload_missing_field', $errors[0]['message']);
        self::assertSame('external_ref', $errors[0]['context']['field']);
        self::assertCount(0, $this->packshot->upserts);
    }

    public function testMissingPackshotIdLogsErrorAndSkips(): void
    {
        $this->handler->handle($this->event(['external_ref' => 'ps:1:42:image:7']));

        $errors = $this->logger->entriesAtLevel('error');
        self::assertNotEmpty($errors);
        self::assertSame('payload_missing_field', $errors[0]['message']);
        self::assertSame('packshot_id', $errors[0]['context']['field']);
    }

    public function testDbExceptionDuringUpsertPropagatesToDispatcher(): void
    {
        $this->heartbeat->nextReturns = true;
        $this->packshot->throwNext = new QameraDbException('boom');

        $this->expectException(QameraDbException::class);
        $this->handler->handle($this->event([
            'external_ref' => 'ps:1:42:image:7',
            'packshot_id' => 'packshot-uuid',
        ]));
    }

    public function testNullableJobIdSurvivesAsNull(): void
    {
        $this->heartbeat->nextReturns = true;
        $this->handler->handle($this->event([
            'external_ref' => 'ps:1:42:image:7',
            'packshot_id' => 'packshot-uuid',
            // job_id intentionally absent
        ]));
        self::assertCount(1, $this->packshot->upserts);
        self::assertNull($this->packshot->upserts[0]['qamera_job_id']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function event(array $payload): WebhookEvent
    {
        return new WebhookEvent('job.completed', 'D1', 'inst-1', $payload);
    }
}
