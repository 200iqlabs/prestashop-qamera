<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Sync;

use Configuration;
use Image;
use PHPUnit\Framework\TestCase;
use QameraAi\Module\Api\QameraApiClient;
use QameraAi\Module\Packshot\Output\ImportedOutputRepository;
use QameraAi\Module\Sync\ExternalRefBuilder;
use QameraAi\Module\Sync\ImageUploadStrategy;
use QameraAi\Module\Sync\InMemoryDedupCache;
use QameraAi\Module\Sync\PrestaShopLoggerWrapper;
use QameraAi\Module\Sync\PrimaryImageResolver;
use QameraAi\Module\Sync\ProductImageSyncService;
use QameraAi\Module\Sync\ProductRefBuilder;
use QameraAi\Module\Tests\Support\RecordingDb;

/**
 * Loop-guard (add-packshot-output-downloader, Layer B): a primary image of
 * Qamera origin (recorded in the imported-output ledger) is never uploaded
 * back to Qamera, preventing a generated scene from re-entering the pipeline
 * as a source image.
 */
final class ProductImageSyncOriginGuardTest extends TestCase
{
    private RecordingDb $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new RecordingDb();
        Configuration::$values = ['QAMERAAI_AUTO_REGISTER_PRODUCTS' => '1'];
        Image::$covers = [];
        Image::$images = [42 => [['id_image' => 200, 'cover' => 0, 'position' => 1]]];
    }

    public function testQameraOriginPrimaryImageIsNotUploaded(): void
    {
        $this->db->getRowScript = [[
            'status' => 'pending',
            'qamera_product_id' => null,
            'qamera_asset_id' => '',
            'display_name_snapshot' => 'Widget',
            'sku_snapshot' => null,
            'description_snapshot' => null,
        ]];

        $ledger = $this->createMock(ImportedOutputRepository::class);
        $ledger->method('isImageImported')->with(200)->willReturn(true);

        $upload = $this->createMock(ImageUploadStrategy::class);
        $upload->expects(self::never())->method('uploadImage');

        $api = $this->createMock(QameraApiClient::class);
        $api->expects(self::never())->method('registerImage');

        $service = $this->makeService($ledger, $upload, $api);

        // Hint 200 resolves to the only (Qamera-origin) image of product 42.
        $service->syncOnImageAdded(42, 200);
    }

    public function testNonOriginPrimaryImageIsUploadedAsBefore(): void
    {
        $this->db->getRowScript = [[
            'status' => 'pending',
            'qamera_product_id' => null,
            'qamera_asset_id' => '',
            'display_name_snapshot' => 'Widget',
            'sku_snapshot' => null,
            'description_snapshot' => null,
        ]];

        $ledger = $this->createMock(ImportedOutputRepository::class);
        $ledger->method('isImageImported')->with(200)->willReturn(false);

        $upload = $this->createMock(ImageUploadStrategy::class);
        $upload->expects(self::once())->method('uploadImage')->willReturn('asset-x');

        $api = $this->createMock(QameraApiClient::class);
        // registerImage will be called on the non-origin path; return value
        // shape is asserted elsewhere — here we only care that upload fired.
        $api->method('registerImage')->willThrowException(new \RuntimeException('stop after upload'));

        $service = $this->makeService($ledger, $upload, $api);

        $service->syncOnImageAdded(42, 200);
    }

    private function makeService(
        ImportedOutputRepository $ledger,
        ImageUploadStrategy $upload,
        QameraApiClient $api
    ): ProductImageSyncService {
        return new ProductImageSyncService(
            $this->db,
            'ps_',
            new ProductRefBuilder(),
            $api,
            $upload,
            new PrimaryImageResolver(),
            $this->createMock(PrestaShopLoggerWrapper::class),
            new InMemoryDedupCache(),
            $ledger,
            new ExternalRefBuilder(new ProductRefBuilder()),
        );
    }
}
