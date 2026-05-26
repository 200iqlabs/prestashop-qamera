<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Sync;

use DateTimeImmutable;
use DateTimeInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use QameraAi\Module\Api\Dto\PresignedUploadResponse;
use QameraAi\Module\Api\Exception\ServerException;
use QameraAi\Module\Api\Exception\TransportException;
use QameraAi\Module\Api\QameraApiClient;
use QameraAi\Module\Sync\PresignedImageUploadStrategy;

final class PresignedImageUploadStrategyTest extends TestCase
{
    private string $tempFile;

    protected function setUp(): void
    {
        parent::setUp();
        $tmp = tempnam(sys_get_temp_dir(), 'qamera-img-');
        self::assertIsString($tmp);
        file_put_contents($tmp, 'image-bytes');
        $this->tempFile = $tmp;
    }

    protected function tearDown(): void
    {
        if (is_file($this->tempFile)) {
            unlink($this->tempFile);
        }
        parent::tearDown();
    }

    private function presigned(string $assetId, string $url, ?string $expiresAt): PresignedUploadResponse
    {
        return new PresignedUploadResponse(
            $assetId,
            'qamera-assets',
            sprintf('uploads/%s', $assetId),
            $url,
            'put-token',
            $expiresAt,
        );
    }

    public function testHappyPathReturnsAssetId(): void
    {
        $expires = (new DateTimeImmutable())->modify('+15 minutes')->format(DateTimeInterface::ATOM);
        $apiClient = $this->createMock(QameraApiClient::class);
        $apiClient->expects(self::once())
            ->method('requestUpload')
            ->with('image.jpg', 'image/jpeg', 11)
            ->willReturn($this->presigned('asset-uuid', 'https://qamera-uploads.example/PUT?sig=...', $expires));

        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::once())
            ->method('request')
            ->with('PUT', 'https://qamera-uploads.example/PUT?sig=...')
            ->willReturn(new Response(200));

        $strategy = new PresignedImageUploadStrategy($apiClient, $http);

        self::assertSame(
            'asset-uuid',
            $strategy->uploadImage($this->tempFile, 'image.jpg', 'image/jpeg', 11)
        );
    }

    public function testExpiredPresignedTriggersRefresh(): void
    {
        $past = (new DateTimeImmutable())->modify('-1 second')->format(DateTimeInterface::ATOM);
        $future = (new DateTimeImmutable())->modify('+15 minutes')->format(DateTimeInterface::ATOM);

        $apiClient = $this->createMock(QameraApiClient::class);
        $apiClient->expects(self::exactly(2))
            ->method('requestUpload')
            ->willReturnOnConsecutiveCalls(
                $this->presigned('stale-asset', 'https://stale.example/PUT', $past),
                $this->presigned('fresh-asset', 'https://fresh.example/PUT', $future),
            );

        $capturedUrl = null;
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::once())
            ->method('request')
            ->willReturnCallback(static function (string $method, string $url) use (&$capturedUrl) {
                $capturedUrl = $url;
                return new Response(200);
            });

        $strategy = new PresignedImageUploadStrategy($apiClient, $http);

        self::assertSame(
            'fresh-asset',
            $strategy->uploadImage($this->tempFile, 'image.jpg', 'image/jpeg', 11)
        );
        self::assertSame('https://fresh.example/PUT', $capturedUrl);
    }

    public function testPutFailureRaisesTransportException(): void
    {
        $future = (new DateTimeImmutable())->modify('+15 minutes')->format(DateTimeInterface::ATOM);

        $apiClient = $this->createMock(QameraApiClient::class);
        $apiClient->method('requestUpload')->willReturn(
            $this->presigned('asset-uuid', 'https://qamera-uploads.example/PUT', $future)
        );

        $http = $this->createMock(ClientInterface::class);
        $http->method('request')->willThrowException(
            new ConnectException('connect timeout', new Request('PUT', 'https://qamera-uploads.example/PUT'))
        );

        $strategy = new PresignedImageUploadStrategy($apiClient, $http);

        $this->expectException(TransportException::class);
        $strategy->uploadImage($this->tempFile, 'image.jpg', 'image/jpeg', 11);
    }

    public function testUpstreamUploadEndpointFailureBubbles(): void
    {
        $apiClient = $this->createMock(QameraApiClient::class);
        $apiClient->method('requestUpload')->willThrowException(
            new ServerException('upstream 503')
        );
        $http = $this->createMock(ClientInterface::class);

        $strategy = new PresignedImageUploadStrategy($apiClient, $http);

        $this->expectException(ServerException::class);
        $strategy->uploadImage($this->tempFile, 'image.jpg', 'image/jpeg', 11);
    }
}
