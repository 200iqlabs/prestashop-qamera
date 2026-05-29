<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Packshot;

use PHPUnit\Framework\TestCase;
use QameraAi\Module\Api\Dto\ErrorBody;
use QameraAi\Module\Api\Dto\JobDto;
use QameraAi\Module\Api\Dto\JobOutput;
use QameraAi\Module\Api\Exception\ServerException;
use QameraAi\Module\Api\QameraApiClient;
use QameraAi\Module\Packshot\PackshotJobRepository;
use QameraAi\Module\Packshot\PackshotJobRow;
use QameraAi\Module\Packshot\PackshotJobWebhookUpdate;
use QameraAi\Module\Sync\PrestaShopLoggerWrapper;
use QameraAi\Module\Tests\Support\TestableJobsStatusRefresher;
use QameraAi\Module\Webhook\Event\QameraDbException;

final class JobsStatusRefresherTest extends TestCase
{
    private const FROZEN = TestableJobsStatusRefresher::FROZEN_TS;

    /** @var QameraApiClient&\PHPUnit\Framework\MockObject\MockObject */
    private $client;
    /** @var PackshotJobRepository&\PHPUnit\Framework\MockObject\MockObject */
    private $repo;
    /** @var PrestaShopLoggerWrapper&\PHPUnit\Framework\MockObject\MockObject */
    private $logger;
    private TestableJobsStatusRefresher $refresher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = $this->createMock(QameraApiClient::class);
        $this->repo = $this->createMock(PackshotJobRepository::class);
        $this->logger = $this->createMock(PrestaShopLoggerWrapper::class);
        $this->refresher = new TestableJobsStatusRefresher($this->repo, $this->client, $this->logger);
    }

    public function testSettledRowWithinTtlReturnsCacheWithoutHttpCall(): void
    {
        $row = $this->row(PackshotJobRow::STATUS_COMPLETED, -10); // 10s ago, settled TTL 3600
        $this->client->expects(self::never())->method('getJob');
        $this->repo->expects(self::never())->method('upsertFromWebhook');

        $result = $this->refresher->refresh($row);

        self::assertSame(PackshotJobRow::STATUS_COMPLETED, $result->status);
        self::assertNull($result->refreshError);
    }

    public function testInFlightPastTtlPullsAndReconciles(): void
    {
        $row = $this->row(PackshotJobRow::STATUS_IN_PROGRESS, -120); // 2min ago, inflight TTL 60
        $captured = null;
        $this->client->method('getJob')->willReturn(
            $this->job('completed', [new JobOutput('https://cdn/out.jpg', 'image/jpeg')], null)
        );
        $this->repo->method('upsertFromWebhook')->willReturnCallback(
            function (PackshotJobWebhookUpdate $u) use (&$captured): void {
                $captured = $u;
            }
        );

        $result = $this->refresher->refresh($row);

        self::assertSame(PackshotJobRow::STATUS_COMPLETED, $result->status);
        self::assertSame('https://cdn/out.jpg', $result->outputUrl);
        self::assertSame(date('Y-m-d H:i:s', self::FROZEN), $result->lastSyncedAt);
        self::assertNotNull($captured);
        self::assertSame(PackshotJobRow::STATUS_COMPLETED, $captured->status);
        self::assertSame('https://cdn/out.jpg', $captured->outputUrl);
    }

    public function testForceBypassesTtlOnSettledRow(): void
    {
        $row = $this->row(PackshotJobRow::STATUS_COMPLETED, -10);
        $this->client->expects(self::once())->method('getJob')->willReturn(
            $this->job('completed', [], null)
        );

        $this->refresher->refresh($row, true);
    }

    public function testNullLastSyncedAlwaysPulls(): void
    {
        $row = $this->row(PackshotJobRow::STATUS_PENDING, null);
        $this->client->expects(self::once())->method('getJob')->willReturn(
            $this->job('in_progress', [], null)
        );

        $result = $this->refresher->refresh($row);
        self::assertSame(PackshotJobRow::STATUS_IN_PROGRESS, $result->status);
    }

    public function testStatusMapRetryPendingToInProgressAndExpiredToCancelled(): void
    {
        $row = $this->row(PackshotJobRow::STATUS_PENDING, null);
        $this->client->method('getJob')->willReturn($this->job('retry_pending', [], null));
        self::assertSame(PackshotJobRow::STATUS_IN_PROGRESS, $this->refresher->refresh($row)->status);

        $row2 = $this->row(PackshotJobRow::STATUS_PENDING, null);
        $this->client = $this->createMock(QameraApiClient::class);
        $this->client->method('getJob')->willReturn($this->job('expired', [], null));
        $refresher2 = new TestableJobsStatusRefresher($this->repo, $this->client, $this->logger);
        self::assertSame(PackshotJobRow::STATUS_CANCELLED, $refresher2->refresh($row2)->status);
    }

    public function testErrorMessageExtractedFromEnvelope(): void
    {
        $row = $this->row(PackshotJobRow::STATUS_IN_PROGRESS, -120);
        $this->client->method('getJob')->willReturn(
            $this->job('failed', [], new ErrorBody('generation_failed', ['en' => 'boom'], false, null))
        );
        $captured = null;
        $this->repo->method('upsertFromWebhook')->willReturnCallback(
            function (PackshotJobWebhookUpdate $u) use (&$captured): void {
                $captured = $u;
            }
        );

        $result = $this->refresher->refresh($row);

        self::assertSame(PackshotJobRow::STATUS_FAILED, $result->status);
        self::assertSame('boom', $result->lastErrorMessage);
        self::assertNotNull($captured);
        self::assertSame('boom', $captured->lastErrorMessage);
    }

    public function testApiExceptionReturnsCachedWithRefreshErrorAndNoWrite(): void
    {
        $row = $this->row(PackshotJobRow::STATUS_IN_PROGRESS, -120);
        $this->client->method('getJob')->willThrowException(new ServerException('upstream 503', 503));
        $this->repo->expects(self::never())->method('upsertFromWebhook');

        $result = $this->refresher->refresh($row);

        self::assertSame(PackshotJobRow::STATUS_IN_PROGRESS, $result->status);
        self::assertNotNull($result->refreshError);
        self::assertStringContainsString('5xx', $result->refreshError);
    }

    public function testExistingExpiryPreservedWhenOutputUrlUnchanged(): void
    {
        // GET /jobs/{id} carries no expiry; a pull that returns the same
        // output URL must NOT wipe the webhook-set output_url_expires_at.
        $row = $this->row(
            PackshotJobRow::STATUS_COMPLETED,
            -10,
            'https://cdn/out.jpg',
            '2026-06-01 00:00:00',
        );
        $this->client->method('getJob')->willReturn(
            $this->job('completed', [new JobOutput('https://cdn/out.jpg', 'image/jpeg')], null)
        );
        $captured = null;
        $this->repo->method('upsertFromWebhook')->willReturnCallback(
            function (PackshotJobWebhookUpdate $u) use (&$captured): void {
                $captured = $u;
            }
        );

        $result = $this->refresher->refresh($row, true); // force past settled TTL

        self::assertNotNull($captured);
        self::assertSame('2026-06-01 00:00:00', $captured->outputUrlExpiresAt);
        self::assertSame('2026-06-01 00:00:00', $result->outputUrlExpiresAt);
    }

    public function testExpiryClearedWhenOutputUrlChanges(): void
    {
        // A genuinely new output URL has an unknown expiry → reset to NULL
        // (the webhook will repopulate it).
        $row = $this->row(
            PackshotJobRow::STATUS_COMPLETED,
            -10,
            'https://cdn/old.jpg',
            '2026-06-01 00:00:00',
        );
        $this->client->method('getJob')->willReturn(
            $this->job('completed', [new JobOutput('https://cdn/new.jpg', 'image/jpeg')], null)
        );
        $captured = null;
        $this->repo->method('upsertFromWebhook')->willReturnCallback(
            function (PackshotJobWebhookUpdate $u) use (&$captured): void {
                $captured = $u;
            }
        );

        $result = $this->refresher->refresh($row, true);

        self::assertNotNull($captured);
        self::assertSame('https://cdn/new.jpg', $captured->outputUrl);
        self::assertNull($captured->outputUrlExpiresAt);
        self::assertNull($result->outputUrlExpiresAt);
    }

    public function testPersistFailureReturnsFreshValuesWithRefreshErrorAndKeepsStaleSync(): void
    {
        $row = $this->row(PackshotJobRow::STATUS_IN_PROGRESS, -120);
        $priorSync = $row->lastSyncedAt;
        $this->client->method('getJob')->willReturn(
            $this->job('completed', [new JobOutput('https://cdn/out.jpg', 'image/jpeg')], null)
        );
        $this->repo->method('upsertFromWebhook')
            ->willThrowException(new QameraDbException('write failed'));

        $result = $this->refresher->refresh($row);

        // Fresh upstream values are surfaced even though the cache write failed,
        self::assertSame(PackshotJobRow::STATUS_COMPLETED, $result->status);
        self::assertSame('https://cdn/out.jpg', $result->outputUrl);
        self::assertNotNull($result->refreshError);
        // ...but last_synced_at stays at the prior (stale) value so the TTL gate
        // re-pulls on the next tick instead of trusting an unpersisted refresh.
        self::assertSame($priorSync, $result->lastSyncedAt);
    }

    private function row(
        string $status,
        ?int $syncedOffsetSeconds,
        ?string $outputUrl = null,
        ?string $outputUrlExpiresAt = null
    ): PackshotJobRow {
        $lastSynced = $syncedOffsetSeconds === null
            ? null
            : date('Y-m-d H:i:s', self::FROZEN + $syncedOffsetSeconds);

        return new PackshotJobRow(
            id: 1,
            qameraJobId: 'job-uuid-1',
            qameraOrderId: 'ord-1',
            idQameraProductLink: 5,
            idShop: 1,
            idProduct: 42,
            packshotExternalRef: 'ps:1:42:packshot:abc',
            status: $status,
            outputUrl: $outputUrl,
            outputUrlExpiresAt: $outputUrlExpiresAt,
            lastErrorMessage: null,
            aiModel: 'openai/gpt-image-1',
            aspectRatio: '1:1',
            imagesCount: 1,
            sessionConfig: [],
            submittedAt: '2026-05-29 10:00:00',
            lastSyncedAt: $lastSynced,
        );
    }

    /**
     * @param JobOutput[] $outputs
     */
    private function job(string $status, array $outputs, ?ErrorBody $error): JobDto
    {
        return new JobDto(
            id: 'job-uuid-1',
            orderId: 'ord-1',
            status: $status,
            jobType: 'packshot',
            provider: 'openai',
            model: 'gpt-image-1',
            unitCost: 10,
            attemptCount: 1,
            outputs: $outputs,
            error: $error,
            externalMetadata: null,
            packshotAssetId: null,
            productLabel: null,
            productRef: 'ps:1:42',
            voting: null,
            votingAt: null,
            createdAt: '2026-05-29 10:00:00',
            updatedAt: '2026-05-29 10:05:00',
            completedAt: null,
        );
    }
}
