<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Sync;

use Configuration;
use Db;
use PHPUnit\Framework\TestCase;
use QameraAi\Module\Api\Dto\ImageResponse;
use QameraAi\Module\Api\Dto\ProductMetadata;
use QameraAi\Module\Api\Dto\RegisterImageRequest;
use QameraAi\Module\Api\Exception\AuthException;
use QameraAi\Module\Api\Exception\RateLimitException;
use QameraAi\Module\Api\Exception\ServerException;
use QameraAi\Module\Api\Exception\TransportException;
use QameraAi\Module\Api\Exception\ValidationException;
use QameraAi\Module\Api\QameraApiClient;
use QameraAi\Module\Sync\ImageUploadStrategy;
use QameraAi\Module\Sync\PrestaShopLoggerWrapper;
use QameraAi\Module\Sync\PrimaryImageResolver;
use QameraAi\Module\Sync\ProductImageSyncService;
use QameraAi\Module\Sync\ProductRefBuilder;
use RuntimeException;

final class ProductImageSyncServiceTest extends TestCase
{
    private const PREFIX = 'ps_';

    /** @var Db&\PHPUnit\Framework\MockObject\MockObject */
    private $db;

    /** @var QameraApiClient&\PHPUnit\Framework\MockObject\MockObject */
    private $apiClient;

    /** @var ImageUploadStrategy&\PHPUnit\Framework\MockObject\MockObject */
    private $uploadStrategy;

    /** @var PrimaryImageResolver&\PHPUnit\Framework\MockObject\MockObject */
    private $resolver;

    /** @var PrestaShopLoggerWrapper&\PHPUnit\Framework\MockObject\MockObject */
    private $logger;

    protected function setUp(): void
    {
        parent::setUp();
        Configuration::$values = [
            'QAMERAAI_AUTO_REGISTER_PRODUCTS' => '1',
            'PS_LANG_DEFAULT:1' => 2,
        ];

        $this->db = $this->getMockBuilder(Db::class)
            ->onlyMethods(['execute', 'executeS', 'getRow'])
            ->getMockForAbstractClass();

        $this->apiClient = $this->createMock(QameraApiClient::class);
        $this->uploadStrategy = $this->createMock(ImageUploadStrategy::class);
        $this->resolver = $this->createMock(PrimaryImageResolver::class);
        $this->logger = $this->createMock(PrestaShopLoggerWrapper::class);
    }

    private function service(): ProductImageSyncService
    {
        return new class (
            $this->db,
            self::PREFIX,
            new ProductRefBuilder(),
            $this->apiClient,
            $this->uploadStrategy,
            $this->resolver,
            $this->logger
        ) extends ProductImageSyncService {
            protected function resolveImagePath(int $idImage): string
            {
                return '/tmp/image-' . $idImage . '.jpg';
            }

            protected function resolveFilename(string $localPath): string
            {
                return basename($localPath);
            }

            protected function resolveContentType(string $localPath): string
            {
                return 'image/jpeg';
            }

            protected function resolveSizeBytes(string $localPath): int
            {
                return 1024;
            }
        };
    }

    /**
     * @param array<string, mixed>|false $row
     */
    private function expectRowRead(int $idProduct, array|false $row): void
    {
        $this->db->method('getRow')
            ->willReturnCallback(static function (string $sql) use ($idProduct, $row) {
                if (str_contains($sql, '= ' . $idProduct . ' AND')) {
                    return $row;
                }
                return false;
            });
    }

    private function makeImageResponse(string $externalRef, string $productId, string $status = 'created'): ImageResponse
    {
        return new ImageResponse($externalRef, $productId, 'img-uuid', $status);
    }

    public function testPendingRowGetsRegisteredOnSuccess(): void
    {
        $this->expectRowRead(42, [
            'status' => 'pending',
            'qamera_product_id' => null,
            'display_name_snapshot' => 'Widget',
            'sku_snapshot' => 'WDG-001',
            'description_snapshot' => 'hello',
        ]);
        $this->resolver->method('resolve')->willReturn(100);
        $this->uploadStrategy->method('uploadImage')
            ->with('/tmp/image-100.jpg', 'image-100.jpg', 'image/jpeg', 1024)
            ->willReturn('asset-uuid');

        $captured = null;
        $this->apiClient->expects(self::once())
            ->method('registerImage')
            ->willReturnCallback(function (RegisterImageRequest $r) use (&$captured): ImageResponse {
                $captured = $r;
                return $this->makeImageResponse('ps:1:42:image:100', 'abc-uuid');
            });

        $capturedSql = '';
        $this->db->expects(self::once())
            ->method('execute')
            ->willReturnCallback(static function (string $sql) use (&$capturedSql): bool {
                $capturedSql = $sql;
                return true;
            });

        $this->service()->syncOnImageAdded(42, 99);

        self::assertInstanceOf(RegisterImageRequest::class, $captured);
        self::assertSame('ps:1:42:image:100', $captured->externalRef);
        self::assertSame('ps:1:42', $captured->productRef);
        self::assertSame('asset-uuid', $captured->assetId);
        self::assertNotNull($captured->productMetadata);
        self::assertSame('Widget', $captured->productMetadata->displayName);
        self::assertStringContainsString("'registered'", $capturedSql);
        self::assertStringContainsString("'abc-uuid'", $capturedSql);
        self::assertStringContainsString('`last_error_message` = NULL', $capturedSql);
    }

    public function testRegisteredRowSkipsProductMetadataInRequest(): void
    {
        $this->expectRowRead(42, [
            'status' => 'registered',
            'qamera_product_id' => 'abc-uuid',
            'display_name_snapshot' => 'Widget',
            'sku_snapshot' => null,
            'description_snapshot' => null,
        ]);
        $this->resolver->expects(self::never())->method('resolve');
        $this->uploadStrategy->method('uploadImage')
            ->with('/tmp/image-99.jpg', 'image-99.jpg', 'image/jpeg', 1024)
            ->willReturn('asset-uuid-2');

        $captured = null;
        $this->apiClient->method('registerImage')
            ->willReturnCallback(function (RegisterImageRequest $r) use (&$captured): ImageResponse {
                $captured = $r;
                return $this->makeImageResponse('ps:1:42:image:99', 'abc-uuid', 'existing');
            });

        $capturedSql = '';
        $this->db->method('execute')->willReturnCallback(
            static function (string $sql) use (&$capturedSql): bool {
                $capturedSql = $sql;
                return true;
            }
        );

        $this->service()->syncOnImageAdded(42, 99);

        self::assertNotNull($captured);
        self::assertNull($captured->productMetadata);
        self::assertStringContainsString('`last_synced_at` = NOW()', $capturedSql);
        self::assertStringNotContainsString("'registered'", $capturedSql);
        self::assertStringNotContainsString('`qamera_product_id`', $capturedSql);
    }

    public function testValidationExceptionMapsToError(): void
    {
        $this->seedPendingRow();
        $this->apiClient->method('registerImage')
            ->willThrowException(new ValidationException('display_name_too_long: too long'));

        $capturedSql = '';
        $this->db->method('execute')->willReturnCallback(
            static function (string $sql) use (&$capturedSql): bool {
                $capturedSql = $sql;
                return true;
            }
        );

        $this->service()->syncOnImageAdded(42, 99);

        self::assertStringContainsString("'error'", $capturedSql);
        self::assertStringContainsString('Upstream validation:', $capturedSql);
    }

    public function testAuthExceptionMapsToError(): void
    {
        $this->seedPendingRow();
        $this->apiClient->method('registerImage')
            ->willThrowException(new AuthException('unauthorized', 401));

        $capturedSql = '';
        $this->db->method('execute')->willReturnCallback(
            static function (string $sql) use (&$capturedSql): bool {
                $capturedSql = $sql;
                return true;
            }
        );

        $this->service()->syncOnImageAdded(42, 99);

        self::assertStringContainsString('API credentials invalid (HTTP 401)', $capturedSql);
    }

    public function testRateLimitExceptionMapsToError(): void
    {
        $this->seedPendingRow();
        $this->apiClient->method('registerImage')
            ->willThrowException(new RateLimitException('rate limited', 30));

        $capturedSql = '';
        $this->db->method('execute')->willReturnCallback(
            static function (string $sql) use (&$capturedSql): bool {
                $capturedSql = $sql;
                return true;
            }
        );

        $this->service()->syncOnImageAdded(42, 99);

        self::assertStringContainsString('Rate limit exceeded', $capturedSql);
    }

    public function testServerExceptionMapsToError(): void
    {
        $this->seedPendingRow();
        $this->apiClient->method('registerImage')
            ->willThrowException(new ServerException('5xx', 503));

        $capturedSql = '';
        $this->db->method('execute')->willReturnCallback(
            static function (string $sql) use (&$capturedSql): bool {
                $capturedSql = $sql;
                return true;
            }
        );

        $this->service()->syncOnImageAdded(42, 99);

        self::assertStringContainsString('Upstream server error (HTTP 5xx) after retries', $capturedSql);
    }

    public function testTransportExceptionMapsToError(): void
    {
        $this->seedPendingRow();
        $this->apiClient->method('registerImage')
            ->willThrowException(new TransportException('cURL error 7: connect failed'));

        $capturedSql = '';
        $this->db->method('execute')->willReturnCallback(
            static function (string $sql) use (&$capturedSql): bool {
                $capturedSql = $sql;
                return true;
            }
        );

        $this->service()->syncOnImageAdded(42, 99);

        self::assertStringContainsString('Network error reaching Qamera AI:', $capturedSql);
        self::assertStringContainsString('cURL error 7', $capturedSql);
    }

    public function testGenericThrowableMapsToError(): void
    {
        $this->seedPendingRow();
        $this->apiClient->method('registerImage')
            ->willThrowException(new RuntimeException('boom'));

        $capturedSql = '';
        $this->db->method('execute')->willReturnCallback(
            static function (string $sql) use (&$capturedSql): bool {
                $capturedSql = $sql;
                return true;
            }
        );

        $this->service()->syncOnImageAdded(42, 99);

        self::assertStringContainsString('Unexpected:', $capturedSql);
        self::assertStringContainsString('RuntimeException', $capturedSql);
    }

    public function testErrorRowRecoversToRegisteredOnSuccess(): void
    {
        $this->expectRowRead(42, [
            'status' => 'error',
            'qamera_product_id' => null,
            'display_name_snapshot' => 'Widget',
            'sku_snapshot' => null,
            'description_snapshot' => null,
        ]);
        $this->resolver->method('resolve')->willReturn(100);
        $this->uploadStrategy->method('uploadImage')->willReturn('asset-uuid');
        $this->apiClient->method('registerImage')->willReturn(
            $this->makeImageResponse('ps:1:42:image:100', 'abc-uuid')
        );

        $capturedSql = '';
        $this->db->method('execute')->willReturnCallback(
            static function (string $sql) use (&$capturedSql): bool {
                $capturedSql = $sql;
                return true;
            }
        );

        $this->service()->syncOnImageAdded(42, 99);

        self::assertStringContainsString("'registered'", $capturedSql);
        self::assertStringContainsString('`last_error_message` = NULL', $capturedSql);
    }

    public function testNoBookkeepingRowIsNoop(): void
    {
        $this->db->method('getRow')->willReturn(false);
        $this->apiClient->expects(self::never())->method('registerImage');
        $this->uploadStrategy->expects(self::never())->method('uploadImage');
        $this->db->expects(self::never())->method('execute');

        $this->logger->expects(self::once())
            ->method('addLog')
            ->with(self::stringContains('no bookkeeping row for id_product=42'));

        $this->service()->syncOnImageAdded(42, 99);
    }

    public function testToggleOffIsNoop(): void
    {
        Configuration::$values['QAMERAAI_AUTO_REGISTER_PRODUCTS'] = '0';
        $this->db->expects(self::never())->method('getRow');
        $this->apiClient->expects(self::never())->method('registerImage');

        $this->service()->syncOnImageAdded(42, 99);
    }

    public function testPrimaryImageResolverReturningNullIsNoop(): void
    {
        $this->expectRowRead(42, [
            'status' => 'pending',
            'qamera_product_id' => null,
            'display_name_snapshot' => 'Widget',
            'sku_snapshot' => null,
            'description_snapshot' => null,
        ]);
        $this->resolver->method('resolve')->willReturn(null);
        $this->uploadStrategy->expects(self::never())->method('uploadImage');
        $this->apiClient->expects(self::never())->method('registerImage');
        $this->db->expects(self::never())->method('execute');

        $this->logger->expects(self::once())
            ->method('addLog')
            ->with(self::stringContains('no resolvable primary image'));

        $this->service()->syncOnImageAdded(42, 99);
    }

    public function testHookFiresMultipleTimesForResizeThumbnailsDeduplicated(): void
    {
        $this->seedPendingRow();
        $this->resolver->method('resolve')->willReturn(100);
        $this->uploadStrategy->expects(self::once())->method('uploadImage')->willReturn('asset-uuid');
        $this->apiClient->expects(self::once())->method('registerImage')->willReturn(
            $this->makeImageResponse('ps:1:42:image:100', 'abc-uuid')
        );
        $this->db->method('execute')->willReturn(true);

        $svc = $this->service();
        $svc->syncOnImageAdded(42, 100);
        $svc->syncOnImageAdded(42, 100);
        $svc->syncOnImageAdded(42, 100);
    }

    public function testPendingRowUsesResolvedPrimaryNotHintForCascadeCreate(): void
    {
        $this->expectRowRead(42, [
            'status' => 'pending',
            'qamera_product_id' => null,
            'display_name_snapshot' => 'Widget',
            'sku_snapshot' => null,
            'description_snapshot' => null,
        ]);
        $this->resolver->expects(self::once())
            ->method('resolve')
            ->with(42, 99, 2)
            ->willReturn(100);

        $this->uploadStrategy->expects(self::once())
            ->method('uploadImage')
            ->with('/tmp/image-100.jpg', 'image-100.jpg', 'image/jpeg', 1024)
            ->willReturn('asset-uuid');

        $captured = null;
        $this->apiClient->expects(self::once())
            ->method('registerImage')
            ->willReturnCallback(function (RegisterImageRequest $r) use (&$captured): ImageResponse {
                $captured = $r;
                return $this->makeImageResponse('ps:1:42:image:100', 'abc-uuid');
            });

        $this->db->method('execute')->willReturn(true);

        $this->service()->syncOnImageAdded(42, 99);

        self::assertInstanceOf(RegisterImageRequest::class, $captured);
        self::assertNotNull($captured->productMetadata);
    }

    public function testRegisteredRowNeverSkipsHintImage(): void
    {
        $this->expectRowRead(42, [
            'status' => 'registered',
            'qamera_product_id' => 'abc-uuid',
            'display_name_snapshot' => 'Widget',
            'sku_snapshot' => null,
            'description_snapshot' => null,
        ]);
        $this->resolver->expects(self::never())->method('resolve');

        $this->uploadStrategy->expects(self::once())
            ->method('uploadImage')
            ->with('/tmp/image-99.jpg', 'image-99.jpg', 'image/jpeg', 1024)
            ->willReturn('asset-uuid-99');

        $captured = null;
        $this->apiClient->expects(self::once())
            ->method('registerImage')
            ->willReturnCallback(function (RegisterImageRequest $r) use (&$captured): ImageResponse {
                $captured = $r;
                return $this->makeImageResponse('ps:1:42:image:99', 'abc-uuid', 'existing');
            });

        $this->db->method('execute')->willReturn(true);

        $this->service()->syncOnImageAdded(42, 99);

        self::assertInstanceOf(RegisterImageRequest::class, $captured);
        self::assertSame('ps:1:42', $captured->productRef);
        self::assertSame('asset-uuid-99', $captured->assetId);
        self::assertNull($captured->productMetadata);
    }

    public function testLastErrorMessageTruncatedAt500Chars(): void
    {
        $this->seedPendingRow();
        $longMessage = str_repeat('a', 1000);
        $this->apiClient->method('registerImage')
            ->willThrowException(new TransportException($longMessage));

        $capturedSql = '';
        $this->db->method('execute')->willReturnCallback(
            static function (string $sql) use (&$capturedSql): bool {
                $capturedSql = $sql;
                return true;
            }
        );

        $this->service()->syncOnImageAdded(42, 99);

        // Pull the quoted last_error_message value out of the UPDATE SQL.
        self::assertMatchesRegularExpression(
            "/`last_error_message` = '([^']+)'/",
            $capturedSql
        );
        preg_match("/`last_error_message` = '([^']+)'/", $capturedSql, $m);
        self::assertSame(500, strlen($m[1]));
    }

    private function seedPendingRow(): void
    {
        $this->expectRowRead(42, [
            'status' => 'pending',
            'qamera_product_id' => null,
            'display_name_snapshot' => 'Widget',
            'sku_snapshot' => null,
            'description_snapshot' => null,
        ]);
        $this->resolver->method('resolve')->willReturn(100);
        $this->uploadStrategy->method('uploadImage')->willReturn('asset-uuid');
    }
}
