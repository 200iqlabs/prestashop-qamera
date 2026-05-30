<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Packshot\Output;

use PHPUnit\Framework\TestCase;
use QameraAi\Module\Api\Dto\JobDto;
use QameraAi\Module\Api\Dto\JobOutput;
use QameraAi\Module\Api\Exception\ServerException;
use QameraAi\Module\Api\QameraApiClient;
use QameraAi\Module\Packshot\Acceptance\PackshotReviewRepository;
use QameraAi\Module\Packshot\Acceptance\PackshotReviewRow;
use QameraAi\Module\Packshot\Output\GalleryImageWriter;
use QameraAi\Module\Packshot\Output\ImportedOutputRepository;
use QameraAi\Module\Packshot\Output\ImportedOutputRow;
use QameraAi\Module\Packshot\Output\OutputImporter;
use QameraAi\Module\Packshot\SyncedProductLinkLookup;
use QameraAi\Module\Sync\PrestaShopLoggerWrapper;

final class OutputImporterTest extends TestCase
{
    /** @var QameraApiClient&\PHPUnit\Framework\MockObject\MockObject */
    private $api;
    /** @var ImportedOutputRepository&\PHPUnit\Framework\MockObject\MockObject */
    private $ledger;
    /** @var PackshotReviewRepository&\PHPUnit\Framework\MockObject\MockObject */
    private $reviews;
    /** @var SyncedProductLinkLookup&\PHPUnit\Framework\MockObject\MockObject */
    private $links;
    /** @var GalleryImageWriter&\PHPUnit\Framework\MockObject\MockObject */
    private $gallery;
    /** @var PrestaShopLoggerWrapper&\PHPUnit\Framework\MockObject\MockObject */
    private $logger;
    private OutputImporter $importer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->api = $this->createMock(QameraApiClient::class);
        $this->ledger = $this->createMock(ImportedOutputRepository::class);
        $this->reviews = $this->createMock(PackshotReviewRepository::class);
        $this->links = $this->createMock(SyncedProductLinkLookup::class);
        $this->gallery = $this->createMock(GalleryImageWriter::class);
        $this->logger = $this->createMock(PrestaShopLoggerWrapper::class);
        $this->importer = new OutputImporter(
            $this->api,
            $this->ledger,
            $this->reviews,
            $this->links,
            $this->gallery,
            $this->logger,
        );
        // By default the product is registered for the shop.
        $this->links->method('findIdLink')->willReturn(5);
        $this->ledger->method('importedIndexes')->willReturn([]);
    }

    public function testPhotoShootCompletedImportsSingleImage(): void
    {
        // No review row → not a gated packshot → photo_shoot / eligible.
        $this->reviews->method('findByJobId')->willReturn(null);
        $this->api->method('getJob')->willReturn(
            $this->job('completed', 'photo_shoot', 'ps:1:42', [
                new JobOutput('https://cdn/scene.jpg', 'image/jpeg'),
            ])
        );
        $this->gallery->expects(self::once())
            ->method('importImage')
            ->with(42, 1, 'https://cdn/scene.jpg')
            ->willReturn(200);
        $recorded = null;
        $this->ledger->method('record')->willReturnCallback(
            function (ImportedOutputRow $r) use (&$recorded): void {
                $recorded = $r;
            }
        );

        $result = $this->importer->import('job-1');

        self::assertNull($result->reason);
        self::assertSame([['output_index' => 0, 'id_image' => 200]], $result->imported);
        self::assertNotNull($recorded);
        self::assertSame(200, $recorded->idImage);
        self::assertSame('image/jpeg', $recorded->outputType);
    }

    public function testAcceptedPackshotImports(): void
    {
        $this->reviews->method('findByJobId')->willReturn(
            $this->review(PackshotReviewRow::VOTING_ACCEPTED)
        );
        $this->api->method('getJob')->willReturn(
            $this->job('completed', 'packshot', 'ps:1:42', [
                new JobOutput('https://cdn/cutout.png', 'image/png'),
            ])
        );
        $this->gallery->expects(self::once())->method('importImage')->willReturn(201);
        $this->ledger->method('record');

        $result = $this->importer->import('job-1');

        self::assertNull($result->reason);
        self::assertCount(1, $result->imported);
    }

    public function testPendingPackshotAbortsWithoutWrites(): void
    {
        $this->reviews->method('findByJobId')->willReturn(
            $this->review(PackshotReviewRow::VOTING_PENDING)
        );
        $this->api->method('getJob')->willReturn(
            $this->job('completed', 'packshot', 'ps:1:42', [
                new JobOutput('https://cdn/cutout.png', 'image/png'),
            ])
        );
        $this->gallery->expects(self::never())->method('importImage');
        $this->ledger->expects(self::never())->method('record');

        $result = $this->importer->import('job-1');

        self::assertSame('packshot_not_accepted', $result->reason);
        self::assertSame([], $result->imported);
    }

    public function testNotCompletedAborts(): void
    {
        $this->reviews->method('findByJobId')->willReturn(null);
        $this->api->method('getJob')->willReturn(
            $this->job('in_progress', 'photo_shoot', 'ps:1:42', [])
        );
        $this->gallery->expects(self::never())->method('importImage');

        $result = $this->importer->import('job-1');

        self::assertSame('not_completed', $result->reason);
    }

    public function testUnparseableProductRefAborts(): void
    {
        $this->reviews->method('findByJobId')->willReturn(null);
        $this->api->method('getJob')->willReturn(
            $this->job('completed', 'photo_shoot', 'not-a-ref', [
                new JobOutput('https://cdn/scene.jpg', 'image/jpeg'),
            ])
        );
        $this->gallery->expects(self::never())->method('importImage');

        $result = $this->importer->import('job-1');

        self::assertSame('invalid_product_ref', $result->reason);
    }

    public function testProductNotRegisteredAborts(): void
    {
        $links = $this->createMock(SyncedProductLinkLookup::class);
        $links->method('findIdLink')->willReturn(null); // not registered
        $importer = new OutputImporter(
            $this->api,
            $this->ledger,
            $this->reviews,
            $links,
            $this->gallery,
            $this->logger,
        );
        $this->reviews->method('findByJobId')->willReturn(null);
        $this->api->method('getJob')->willReturn(
            $this->job('completed', 'photo_shoot', 'ps:1:99', [
                new JobOutput('https://cdn/scene.jpg', 'image/jpeg'),
            ])
        );
        $this->gallery->expects(self::never())->method('importImage');

        $result = $importer->import('job-1');

        self::assertSame('product_not_registered', $result->reason);
    }

    public function testPartialRetrySkipsLedgeredIndexes(): void
    {
        $ledger = $this->createMock(ImportedOutputRepository::class);
        $ledger->method('importedIndexes')->willReturn([0]); // output 0 already imported
        $importer = new OutputImporter(
            $this->api,
            $ledger,
            $this->reviews,
            $this->links,
            $this->gallery,
            $this->logger,
        );
        $this->reviews->method('findByJobId')->willReturn(null);
        $this->api->method('getJob')->willReturn(
            $this->job('completed', 'photo_shoot', 'ps:1:42', [
                new JobOutput('https://cdn/a.jpg', 'image/jpeg'),
                new JobOutput('https://cdn/b.jpg', 'image/jpeg'),
            ])
        );
        $this->gallery->expects(self::once())
            ->method('importImage')
            ->with(42, 1, 'https://cdn/b.jpg')
            ->willReturn(202);
        $ledger->method('record');

        $result = $importer->import('job-1');

        self::assertSame([['output_index' => 1, 'id_image' => 202]], $result->imported);
        self::assertSame([0], $result->skipped);
    }

    public function testNonImageOutputRecordedNotPlaced(): void
    {
        $this->reviews->method('findByJobId')->willReturn(null);
        $this->api->method('getJob')->willReturn(
            $this->job('completed', 'photo_shoot', 'ps:1:42', [
                new JobOutput('https://cdn/reel.mp4', 'video/mp4'),
            ])
        );
        $this->gallery->expects(self::never())->method('importImage');
        $recorded = null;
        $this->ledger->method('record')->willReturnCallback(
            function (ImportedOutputRow $r) use (&$recorded): void {
                $recorded = $r;
            }
        );

        $result = $this->importer->import('job-1');

        self::assertNull($result->reason);
        self::assertSame([], $result->imported);
        self::assertSame([0], $result->recordedNonImage);
        self::assertNotNull($recorded);
        self::assertNull($recorded->idImage);
        self::assertSame('video/mp4', $recorded->outputType);
    }

    public function testOneFailingOutputDoesNotAbortTheRest(): void
    {
        $this->reviews->method('findByJobId')->willReturn(null);
        $this->api->method('getJob')->willReturn(
            $this->job('completed', 'photo_shoot', 'ps:1:42', [
                new JobOutput('https://cdn/a.jpg', 'image/jpeg'),
                new JobOutput('https://cdn/b.jpg', 'image/jpeg'),
            ])
        );
        $this->gallery->method('importImage')->willReturnCallback(
            function (int $p, int $s, string $url): int {
                if ($url === 'https://cdn/a.jpg') {
                    throw new \RuntimeException('download failed');
                }
                return 203;
            }
        );
        $this->ledger->method('record');

        $result = $this->importer->import('job-1');

        self::assertSame([['output_index' => 1, 'id_image' => 203]], $result->imported);
        self::assertCount(1, $result->failures);
        self::assertSame(0, $result->failures[0]['output_index']);
    }

    public function testApiFailureAbortsGracefully(): void
    {
        $this->reviews->method('findByJobId')->willReturn(null);
        $this->api->method('getJob')->willThrowException(
            new ServerException('boom', 500)
        );
        $this->gallery->expects(self::never())->method('importImage');

        $result = $this->importer->import('job-1');

        self::assertSame('api_error', $result->reason);
    }

    public function testJobGateReasonPureLogic(): void
    {
        self::assertSame('not_completed', $this->importer->jobGateReason('in_progress', null));
        self::assertNull($this->importer->jobGateReason('completed', null));
        self::assertNull(
            $this->importer->jobGateReason('completed', $this->review(PackshotReviewRow::VOTING_ACCEPTED))
        );
        self::assertSame(
            'packshot_not_accepted',
            $this->importer->jobGateReason('completed', $this->review(PackshotReviewRow::VOTING_PENDING))
        );
        self::assertSame(
            'packshot_not_accepted',
            $this->importer->jobGateReason('completed', $this->review(PackshotReviewRow::VOTING_REJECTED))
        );
    }

    public function testGridStateImportedWinsRegardlessOfGate(): void
    {
        // Already in the ledger → "imported" even if the (packshot) review is pending.
        $state = $this->importer->gridState('completed', $this->review(PackshotReviewRow::VOTING_PENDING), [0]);
        self::assertSame('imported', $state['state']);
    }

    public function testGridStateActiveForCompletedPhotoShoot(): void
    {
        $state = $this->importer->gridState('completed', null, []);
        self::assertSame('active', $state['state']);
    }

    public function testGridStateDisabledForPendingPackshot(): void
    {
        $state = $this->importer->gridState('completed', $this->review(PackshotReviewRow::VOTING_PENDING), []);
        self::assertSame('disabled', $state['state']);
        self::assertSame('packshot_not_accepted', $state['reason']);
    }

    public function testGridStateAbsentWhenNotCompleted(): void
    {
        $state = $this->importer->gridState('in_progress', null, []);
        self::assertSame('absent', $state['state']);
    }

    private function review(string $voting): PackshotReviewRow
    {
        return new PackshotReviewRow(
            id: 1,
            qameraJobId: 'job-1',
            idShop: 1,
            idProduct: 42,
            productRef: 'ps:1:42',
            assetUrl: 'https://cdn/preview.jpg',
            voting: $voting,
            votingAt: null,
            generatedAt: '2026-05-30 10:00:00',
        );
    }

    /**
     * @param JobOutput[] $outputs
     */
    private function job(string $status, string $jobType, string $productRef, array $outputs): JobDto
    {
        return new JobDto(
            id: 'job-1',
            orderId: 'ord-1',
            status: $status,
            jobType: $jobType,
            provider: 'openai',
            model: 'gpt-image-1',
            unitCost: 10,
            attemptCount: 1,
            outputs: $outputs,
            error: null,
            externalMetadata: null,
            packshotAssetId: null,
            productLabel: null,
            productRef: $productRef,
            voting: null,
            votingAt: null,
            createdAt: '2026-05-30 10:00:00',
            updatedAt: '2026-05-30 10:05:00',
            completedAt: '2026-05-30 10:05:00',
        );
    }
}
