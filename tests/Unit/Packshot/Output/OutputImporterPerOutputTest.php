<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Packshot\Output;

use PHPUnit\Framework\TestCase;
use QameraAi\Module\Api\Dto\JobDto;
use QameraAi\Module\Api\Dto\JobOutput;
use QameraAi\Module\Api\QameraApiClient;
use QameraAi\Module\Packshot\Acceptance\PackshotReviewRepository;
use QameraAi\Module\Packshot\Acceptance\PackshotReviewRow;
use QameraAi\Module\Packshot\Output\GalleryImageWriter;
use QameraAi\Module\Packshot\Output\ImportedOutputRepository;
use QameraAi\Module\Packshot\Output\ImportedOutputRow;
use QameraAi\Module\Packshot\Output\OutputImporter;
use QameraAi\Module\Packshot\SyncedProductLinkLookup;
use QameraAi\Module\Sync\PrestaShopLoggerWrapper;

/**
 * 4.1 — single-output import keyed `(qamera_job_id, output_index)` for the
 * browse "Add to product gallery" action: places only the targeted output,
 * idempotent on an existing ledger row, same photo_shoot-unconditional /
 * packshot-accepted gate, and leaves the job's other outputs untouched.
 */
final class OutputImporterPerOutputTest extends TestCase
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
        $this->links->method('findIdLink')->willReturn(5);
        $this->ledger->method('importedIndexes')->willReturn([]);
    }

    public function testImportsOnlyTheTargetedOutput(): void
    {
        $this->reviews->method('findByJobId')->willReturn(null);
        $this->api->method('getJob')->willReturn(
            $this->job('completed', 'photo_shoot', 'ps:1:42', [
                new JobOutput('https://cdn/a.jpg', 'image/jpeg'),
                new JobOutput('https://cdn/b.jpg', 'image/jpeg'),
                new JobOutput('https://cdn/c.jpg', 'image/jpeg'),
            ])
        );
        $this->gallery->expects(self::once())
            ->method('importImage')
            ->with(42, 1, 'https://cdn/b.jpg')
            ->willReturn(210);
        $recorded = null;
        $this->ledger->method('record')->willReturnCallback(
            function (ImportedOutputRow $r) use (&$recorded): void {
                $recorded = $r;
            }
        );

        $result = $this->importer->importOutput('job-1', 1);

        self::assertNull($result->reason);
        self::assertSame([['output_index' => 1, 'id_image' => 210]], $result->imported);
        self::assertNotNull($recorded);
        self::assertSame(1, $recorded->outputIndex);
        self::assertSame(210, $recorded->idImage);
    }

    public function testIdempotentWhenLedgerRowExists(): void
    {
        $ledger = $this->createMock(ImportedOutputRepository::class);
        $ledger->method('importedIndexes')->willReturn([1]);
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
        $this->gallery->expects(self::never())->method('importImage');
        $ledger->expects(self::never())->method('record');

        $result = $importer->importOutput('job-1', 1);

        self::assertNull($result->reason);
        self::assertSame([], $result->imported);
        self::assertSame([1], $result->skipped);
    }

    public function testAcceptedPackshotOutputImports(): void
    {
        $this->reviews->method('findByJobId')->willReturn(
            $this->review(PackshotReviewRow::VOTING_ACCEPTED)
        );
        $this->api->method('getJob')->willReturn(
            $this->job('completed', 'packshot', 'ps:1:42', [
                new JobOutput('https://cdn/cutout.png', 'image/png'),
            ])
        );
        $this->gallery->expects(self::once())->method('importImage')->willReturn(211);
        $this->ledger->method('record');

        $result = $this->importer->importOutput('job-1', 0);

        self::assertNull($result->reason);
        self::assertCount(1, $result->imported);
    }

    public function testPendingPackshotOutputRejected(): void
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

        $result = $this->importer->importOutput('job-1', 0);

        self::assertSame('packshot_not_accepted', $result->reason);
        self::assertSame([], $result->imported);
    }

    public function testOutputIndexOutOfRangeAborts(): void
    {
        $this->reviews->method('findByJobId')->willReturn(null);
        $this->api->method('getJob')->willReturn(
            $this->job('completed', 'photo_shoot', 'ps:1:42', [
                new JobOutput('https://cdn/a.jpg', 'image/jpeg'),
            ])
        );
        $this->gallery->expects(self::never())->method('importImage');

        $result = $this->importer->importOutput('job-1', 5);

        self::assertSame('output_not_found', $result->reason);
    }

    public function testNonImageTargetedOutputRecordedNotPlaced(): void
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

        $result = $this->importer->importOutput('job-1', 0);

        self::assertNull($result->reason);
        self::assertSame([], $result->imported);
        self::assertSame([0], $result->recordedNonImage);
        self::assertNotNull($recorded);
        self::assertNull($recorded->idImage);
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
