<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Gallery;

use PHPUnit\Framework\TestCase;
use QameraAi\Module\Api\Dto\ImageResponse;
use QameraAi\Module\Api\Dto\PackshotResponse;
use QameraAi\Module\Api\Dto\RegisterImageRequest;
use QameraAi\Module\Api\Dto\RegisterPackshotRequest;
use QameraAi\Module\Api\QameraApiClient;
use QameraAi\Module\Gallery\GalleryIngestOrchestrator;
use QameraAi\Module\Gallery\IngestItem;
use QameraAi\Module\Gallery\ProductImageFileResolver;
use QameraAi\Module\Gallery\ResolvedImageFile;
use QameraAi\Module\Gallery\WriteScopeChecker;
use QameraAi\Module\Sync\ExternalRefBuilder;
use QameraAi\Module\Sync\ImageUploadStrategy;
use QameraAi\Module\Sync\ProductRefBuilder;

final class GalleryIngestOrchestratorTest extends TestCase
{
    /** @var ProductImageFileResolver&\PHPUnit\Framework\MockObject\MockObject */
    private $fileResolver;

    /** @var ImageUploadStrategy&\PHPUnit\Framework\MockObject\MockObject */
    private $upload;

    /** @var QameraApiClient&\PHPUnit\Framework\MockObject\MockObject */
    private $api;

    /** @var WriteScopeChecker&\PHPUnit\Framework\MockObject\MockObject */
    private $scope;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fileResolver = $this->createMock(ProductImageFileResolver::class);
        $this->upload = $this->createMock(ImageUploadStrategy::class);
        $this->api = $this->createMock(QameraApiClient::class);
        $this->scope = $this->createMock(WriteScopeChecker::class);
        $this->scope->method('hasWriteScope')->willReturn(true);
    }

    private function orchestrator(int $maxUploadBytes = 10_000_000): GalleryIngestOrchestrator
    {
        return new GalleryIngestOrchestrator(
            $this->fileResolver,
            $this->upload,
            $this->api,
            new ExternalRefBuilder(new ProductRefBuilder()),
            new ProductRefBuilder(),
            $this->scope,
            $maxUploadBytes,
            2,
            0
        );
    }

    private function resolvesFile(int $size = 1024): void
    {
        $this->fileResolver->method('resolve')
            ->willReturn(new ResolvedImageFile('/tmp/p/42.jpg', 'image/jpeg', $size));
    }

    public function testAddAsProductRegistersImageOnly(): void
    {
        $this->resolvesFile();
        $this->upload->expects(self::once())
            ->method('uploadImage')
            ->with('/tmp/p/42.jpg', '42.jpg', 'image/jpeg', 1024)
            ->willReturn('asset-1');

        $captured = null;
        $this->api->expects(self::once())
            ->method('registerImage')
            ->willReturnCallback(function (RegisterImageRequest $r) use (&$captured): ImageResponse {
                $captured = $r;
                return new ImageResponse($r->externalRef, 'prod-1', 'img-1', 'created');
            });
        $this->api->expects(self::never())->method('registerPackshot');

        $result = $this->orchestrator()->ingest(
            new IngestItem(1, 42, 42, IngestItem::ACTION_PRODUCT)
        );

        self::assertFalse($result->isError());
        self::assertSame('created', $result->status);
        self::assertSame('ps:1:42:image:42', $result->imageRef);
        self::assertNull($result->packshotRef);
        self::assertSame('asset-1', $result->assetId);
        self::assertSame('ps:1:42:image:42', $captured->externalRef);
        self::assertSame('ps:1:42', $captured->productRef);
        self::assertSame('asset-1', $captured->assetId);
    }

    public function testAddAsPackshotRegistersImageThenPackshotWithSourceRef(): void
    {
        $this->resolvesFile();
        $this->upload->method('uploadImage')->willReturn('asset-2');

        $order = [];
        $this->api->method('registerImage')
            ->willReturnCallback(function (RegisterImageRequest $r) use (&$order): ImageResponse {
                $order[] = 'image';
                return new ImageResponse($r->externalRef, 'prod-1', 'img-1', 'created');
            });
        $capturedPack = null;
        $this->api->method('registerPackshot')
            ->willReturnCallback(function (RegisterPackshotRequest $r) use (&$order, &$capturedPack): PackshotResponse {
                $order[] = 'packshot';
                $capturedPack = $r;
                return new PackshotResponse($r->externalRef, 'prod-1', 'pack-1', 'created');
            });

        $result = $this->orchestrator()->ingest(
            new IngestItem(1, 42, 42, IngestItem::ACTION_PACKSHOT)
        );

        self::assertFalse($result->isError());
        self::assertSame(['image', 'packshot'], $order, 'image must register before packshot');
        self::assertSame('ps:1:42:pack:42', $result->packshotRef);
        self::assertSame('ps:1:42:pack:42', $capturedPack->externalRef);
        self::assertSame('ps:1:42', $capturedPack->productRef);
        self::assertSame('asset-2', $capturedPack->assetId);
        // source_image_ref MUST equal the image's external_ref so the packshot
        // lands with a non-null source_image_id (the null-source trap fix).
        self::assertSame('ps:1:42:image:42', $capturedPack->sourceImageRef);
    }

    public function testReIngestSurfacesExistingStatus(): void
    {
        $this->resolvesFile();
        $this->upload->method('uploadImage')->willReturn('asset-3');
        $this->api->method('registerImage')
            ->willReturnCallback(static fn (RegisterImageRequest $r): ImageResponse =>
                new ImageResponse($r->externalRef, 'prod-1', 'img-1', 'existing'));

        $result = $this->orchestrator()->ingest(
            new IngestItem(1, 42, 42, IngestItem::ACTION_PRODUCT)
        );

        self::assertFalse($result->isError());
        self::assertSame('existing', $result->status);
    }

    public function testOversizeFileRejectedBeforeUpload(): void
    {
        $this->resolvesFile(50_000_000);
        $this->upload->expects(self::never())->method('uploadImage');
        $this->api->expects(self::never())->method('registerImage');

        $result = $this->orchestrator(10_000_000)->ingest(
            new IngestItem(1, 42, 42, IngestItem::ACTION_PRODUCT)
        );

        self::assertTrue($result->isError());
        self::assertSame('invalid_input', $result->errorCode);
        self::assertFalse($result->retryable);
    }

    public function testMissingWriteScopeBlocksIngestBeforeUpload(): void
    {
        $scope = $this->createMock(WriteScopeChecker::class);
        $scope->method('hasWriteScope')->willReturn(false);
        $this->upload->expects(self::never())->method('uploadImage');
        $this->api->expects(self::never())->method('registerImage');

        $orchestrator = new GalleryIngestOrchestrator(
            $this->fileResolver,
            $this->upload,
            $this->api,
            new ExternalRefBuilder(new ProductRefBuilder()),
            new ProductRefBuilder(),
            $scope,
            10_000_000,
            2,
            0
        );

        $result = $orchestrator->ingest(new IngestItem(1, 42, 42, IngestItem::ACTION_PRODUCT));

        self::assertTrue($result->isError());
        self::assertSame('forbidden', $result->errorCode);
        self::assertFalse($result->retryable);
    }
}
