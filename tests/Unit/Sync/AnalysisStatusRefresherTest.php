<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Sync;

use Db;
use PHPUnit\Framework\TestCase;
use QameraAi\Module\Api\Dto\ProductDetailResponse;
use QameraAi\Module\Api\Dto\ProductImageDto;
use QameraAi\Module\Api\Exception\ServerException;
use QameraAi\Module\Api\Exception\ValidationException;
use QameraAi\Module\Api\QameraApiClient;
use QameraAi\Module\Packshot\SyncedProductLink;
use QameraAi\Module\Sync\AnalysisStatusRefresher;
use QameraAi\Module\Sync\PrestaShopLoggerWrapper;
use QameraAi\Module\Tests\Support\TestableAnalysisStatusRefresher;

final class AnalysisStatusRefresherTest extends TestCase
{
    private const PREFIX = 'ps_';
    private const FROZEN_TS = 1779000000;
    // FROZEN_NOW is the local-TZ date() of FROZEN_TS — kept consistent
    // by deriving from the same epoch the testable refresher exposes.

    /** @var Db&\PHPUnit\Framework\MockObject\MockObject */
    private $db;

    /** @var QameraApiClient&\PHPUnit\Framework\MockObject\MockObject */
    private $client;

    /** @var PrestaShopLoggerWrapper&\PHPUnit\Framework\MockObject\MockObject */
    private $logger;

    private TestableAnalysisStatusRefresher $refresher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = $this->createMock(Db::class);
        // The refresher routes string interpolation through Db::escape;
        // the mock's default null return would silently swallow the
        // escaped enum literal into an empty string and the UPDATE
        // assertion would mismatch. Return the input unchanged so the
        // SQL fragment is verifiable.
        $this->db->method('escape')->willReturnCallback(
            static fn ($value, $html = false, $bqSql = false) => (string) $value,
        );
        $this->client = $this->createMock(QameraApiClient::class);
        $this->logger = $this->createMock(PrestaShopLoggerWrapper::class);
        $this->refresher = new TestableAnalysisStatusRefresher(
            $this->db,
            self::PREFIX,
            $this->client,
            $this->logger,
        );
    }

    public function testTtlFreshProcessingRowReturnsCacheWithoutHttpCall(): void
    {
        // refreshed 10s ago, processing → TTL 60s → fresh
        $link = $this->makeLink(
            analysisStatus: 'processing',
            analysisDescribedCount: 0,
            analysisTotalCount: 1,
            analysisRefreshedAtTsOffset: -10,
        );
        $this->client->expects(self::never())->method('getProduct');
        $this->db->expects(self::never())->method('execute');

        $result = $this->refresher->refresh($link, force: false);

        self::assertSame('processing', $result->analysisStatus);
        self::assertSame(0, $result->describedCount);
        self::assertSame(1, $result->totalCount);
        self::assertNull($result->refreshError);
    }

    public function testTtlStaleProcessingPullsAndWritesBack(): void
    {
        $link = $this->makeLink(
            analysisStatus: 'processing',
            analysisDescribedCount: 0,
            analysisTotalCount: 1,
            analysisRefreshedAtTsOffset: -90,
        );
        $this->client->expects(self::once())
            ->method('getProduct')
            ->with('ps:1:42')
            ->willReturn($this->makeDetail([
                $this->makeImage('described'),
            ]));
        $this->db->expects(self::once())
            ->method('execute')
            ->with(self::callback(function ($sql): bool {
                self::assertStringContainsString("'described'", $sql);
                self::assertStringContainsString("`analysis_described_count` = 1", $sql);
                self::assertStringContainsString("`analysis_total_count` = 1", $sql);
                self::assertStringContainsString("'" . date('Y-m-d H:i:s', self::FROZEN_TS) . "'", $sql);
                return true;
            }))
            ->willReturn(true);

        $result = $this->refresher->refresh($link, force: false);

        self::assertSame('described', $result->analysisStatus);
        self::assertSame(1, $result->describedCount);
        self::assertSame(1, $result->totalCount);
        self::assertSame(date('Y-m-d H:i:s', self::FROZEN_TS), $result->refreshedAt);
    }

    public function testForceBypassesTtlOnFreshDescribedRow(): void
    {
        $link = $this->makeLink(
            analysisStatus: 'described',
            analysisDescribedCount: 1,
            analysisTotalCount: 1,
            analysisRefreshedAtTsOffset: -1800, // 30 min, inside 3600s TTL
        );
        $this->client->expects(self::once())
            ->method('getProduct')
            ->willReturn($this->makeDetail([$this->makeImage('described')]));
        $this->db->expects(self::once())->method('execute')->willReturn(true);

        $result = $this->refresher->refresh($link, force: true);
        self::assertSame('described', $result->analysisStatus);
    }

    public function testNullRefreshedAtAlwaysPulls(): void
    {
        $link = $this->makeLink(
            analysisStatus: null,
            analysisDescribedCount: null,
            analysisTotalCount: null,
            analysisRefreshedAtTsOffset: null,
        );
        $this->client->expects(self::once())
            ->method('getProduct')
            ->willReturn($this->makeDetail([$this->makeImage('pending')]));
        $this->db->expects(self::once())->method('execute')->willReturn(true);

        $result = $this->refresher->refresh($link, force: false);
        self::assertSame('pending', $result->analysisStatus);
        self::assertSame(0, $result->describedCount);
        self::assertSame(1, $result->totalCount);
    }

    public function testUpstreamFailurePreservesCacheAndSetsRefreshError(): void
    {
        $link = $this->makeLink(
            analysisStatus: 'processing',
            analysisDescribedCount: 0,
            analysisTotalCount: 1,
            analysisRefreshedAtTsOffset: -3600,
        );
        $this->client->expects(self::once())
            ->method('getProduct')
            ->willThrowException(new ServerException('boom'));
        $this->db->expects(self::never())->method('execute');
        $this->logger->expects(self::once())
            ->method('addLog')
            ->with(
                self::stringContains('product_ref=ps:1:42'),
                2,
            );

        $result = $this->refresher->refresh($link, force: true);

        self::assertSame('processing', $result->analysisStatus); // cache stands
        self::assertSame(0, $result->describedCount);
        self::assertSame(1, $result->totalCount);
        self::assertStringStartsWith('Upstream server error (HTTP 5xx)', (string) $result->refreshError);
    }

    public function testValidationExceptionFromUpstreamSanitisesIntoRefreshError(): void
    {
        $link = $this->makeLink(
            analysisStatus: null,
            analysisDescribedCount: null,
            analysisTotalCount: null,
            analysisRefreshedAtTsOffset: null,
        );
        $this->client->expects(self::once())
            ->method('getProduct')
            ->willThrowException(ValidationException::malformedResponse('analysis_status'));

        $result = $this->refresher->refresh($link, force: false);

        self::assertNull($result->analysisStatus);
        self::assertStringStartsWith('Upstream validation:', (string) $result->refreshError);
    }

    /* --------------------------------------------------------------------
     * §aggregate(): pure-function matrix per the spec scenarios.
     * ------------------------------------------------------------------ */

    public function testAggregateSingleDescribed(): void
    {
        $r = AnalysisStatusRefresher::aggregate([$this->makeImage('described')]);
        self::assertSame(['status' => 'described', 'described' => 1, 'total' => 1], $r);
    }

    public function testAggregateSingleProcessing(): void
    {
        $r = AnalysisStatusRefresher::aggregate([$this->makeImage('processing')]);
        self::assertSame(['status' => 'processing', 'described' => 0, 'total' => 1], $r);
    }

    public function testAggregateSingleErrorWithNoDescribedYieldsError(): void
    {
        $r = AnalysisStatusRefresher::aggregate([$this->makeImage('error')]);
        self::assertSame(['status' => 'error', 'described' => 0, 'total' => 1], $r);
    }

    public function testAggregateMultiDescribedPlusProcessingYieldsPartial(): void
    {
        $r = AnalysisStatusRefresher::aggregate([
            $this->makeImage('described'),
            $this->makeImage('processing'),
        ]);
        self::assertSame(['status' => 'partial', 'described' => 1, 'total' => 2], $r);
    }

    public function testAggregateMultiDescribedPlusErrorYieldsPartialNotError(): void
    {
        $r = AnalysisStatusRefresher::aggregate([
            $this->makeImage('described'),
            $this->makeImage('error'),
        ]);
        self::assertSame(['status' => 'partial', 'described' => 1, 'total' => 2], $r);
    }

    public function testAggregateAllErrorMultiYieldsError(): void
    {
        $r = AnalysisStatusRefresher::aggregate([
            $this->makeImage('error'),
            $this->makeImage('error'),
        ]);
        self::assertSame(['status' => 'error', 'described' => 0, 'total' => 2], $r);
    }

    public function testAggregateEmptyImagesYieldsNullStatus(): void
    {
        $r = AnalysisStatusRefresher::aggregate([]);
        self::assertSame(['status' => null, 'described' => 0, 'total' => 0], $r);
    }

    public function testAggregateAllPendingYieldsPending(): void
    {
        $r = AnalysisStatusRefresher::aggregate([
            $this->makeImage('pending'),
            $this->makeImage('pending'),
        ]);
        self::assertSame(['status' => 'pending', 'described' => 0, 'total' => 2], $r);
    }

    /* --------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    private function makeLink(
        ?string $analysisStatus,
        ?int $analysisDescribedCount,
        ?int $analysisTotalCount,
        ?int $analysisRefreshedAtTsOffset,
    ): SyncedProductLink {
        $refreshedAt = $analysisRefreshedAtTsOffset === null
            ? null
            : date('Y-m-d H:i:s', self::FROZEN_TS + $analysisRefreshedAtTsOffset);

        return new SyncedProductLink(
            idLink: 1,
            idShop: 1,
            idProduct: 42,
            qameraAssetId: 'asset-uuid',
            qameraProductRef: 'ps:1:42',
            displayNameSnapshot: 'Widget',
            analysisStatus: $analysisStatus,
            analysisDescribedCount: $analysisDescribedCount,
            analysisTotalCount: $analysisTotalCount,
            analysisRefreshedAt: $refreshedAt,
        );
    }

    private function makeImage(string $analysisStatus): ProductImageDto
    {
        return new ProductImageDto(
            id: 'img-uuid',
            externalRef: null,
            productId: 'prod-uuid',
            assetId: 'asset-uuid',
            byteSize: 100,
            contentType: 'image/jpeg',
            width: null,
            height: null,
            sha256: str_repeat('a', 64),
            analysisStatus: $analysisStatus,
            analyzedAt: $analysisStatus === 'described' ? '2026-05-28T11:59:00Z' : null,
            createdAt: '2026-05-28T11:58:00Z',
        );
    }

    /**
     * @param ProductImageDto[] $images
     */
    private function makeDetail(array $images): ProductDetailResponse
    {
        return new ProductDetailResponse(
            id: 'prod-uuid',
            externalRef: 'ps:1:42',
            displayName: 'Widget',
            sku: null,
            description: null,
            sourceMetadata: [],
            deletedAt: null,
            createdAt: '2026-05-28T11:00:00Z',
            updatedAt: '2026-05-28T11:59:00Z',
            images: $images,
            imagesTruncated: false,
            packshots: [],
            packshotsTruncated: false,
        );
    }
}
