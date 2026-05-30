<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Gallery;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use PHPUnit\Framework\TestCase;
use QameraAi\Module\Api\Internal\ErrorEnvelopeParser;
use QameraAi\Module\Api\Internal\HeaderBuilder;
use QameraAi\Module\Api\Internal\IdempotencyKeyGenerator;
use QameraAi\Module\Api\Internal\JsonDecoder;
use QameraAi\Module\Api\Internal\RetryDecider;
use QameraAi\Module\Api\QameraApiClient;
use QameraAi\Module\Gallery\GalleryIngestOrchestrator;
use QameraAi\Module\Gallery\IngestItem;
use QameraAi\Module\Gallery\ProductImageFileResolver;
use QameraAi\Module\Gallery\ResolvedImageFile;
use QameraAi\Module\Gallery\WriteScopeChecker;
use QameraAi\Module\Sync\ExternalRefBuilder;
use QameraAi\Module\Sync\ImageUploadStrategy;
use QameraAi\Module\Sync\ProductRefBuilder;

/**
 * 2.3 — drives the orchestrator through a real {@see QameraApiClient} backed
 * by a Guzzle {@see MockHandler}, asserting each upstream error code maps to
 * the right per-item taxonomy outcome (retryable vs terminal). Also covers
 * the 2.4 live-403 path.
 */
final class GalleryIngestErrorTaxonomyTest extends TestCase
{
    private const BASE_URL = 'https://qamera.test/api/v1/plugin';

    /**
     * @param list<Response> $queue
     */
    private function clientWith(array $queue): QameraApiClient
    {
        $mock = new MockHandler($queue);
        $stack = HandlerStack::create($mock);

        $noSleepDecider = new class extends RetryDecider {
            public function delayMs(int $retries, ?ResponseInterface $response): int
            {
                return 0;
            }
        };

        return new QameraApiClient(
            self::BASE_URL,
            new HeaderBuilder('api_key_dummy', 'QameraAi-PrestaShop-Module/1.0.0 (9.0.0)', 'en'),
            $noSleepDecider,
            new ErrorEnvelopeParser(),
            new IdempotencyKeyGenerator(),
            new JsonDecoder(),
            $stack,
        );
    }

    private function errorResponse(int $status, string $code): Response
    {
        return new Response($status, [], (string) json_encode([
            'error' => [
                'code' => $code,
                'message_i18n' => ['en' => 'msg for ' . $code],
                'retryable' => in_array($code, ['rate_limit_exceeded', 'internal_error'], true),
            ],
        ]));
    }

    private function orchestrator(QameraApiClient $client): GalleryIngestOrchestrator
    {
        $fileResolver = $this->createMock(ProductImageFileResolver::class);
        $fileResolver->method('resolve')
            ->willReturn(new ResolvedImageFile('/tmp/p/42.jpg', 'image/jpeg', 1024));

        $upload = $this->createMock(ImageUploadStrategy::class);
        $upload->method('uploadImage')->willReturn('asset-x');

        $scope = $this->createMock(WriteScopeChecker::class);
        $scope->method('hasWriteScope')->willReturn(true);

        // maxRetries 0: the client's own RetryDecider already exhausts 5xx/429
        // attempts; the orchestrator must not re-loop on top of that here.
        return new GalleryIngestOrchestrator(
            $fileResolver,
            $upload,
            $client,
            new ExternalRefBuilder(new ProductRefBuilder()),
            new ProductRefBuilder(),
            $scope,
            10_000_000,
            0,
            0
        );
    }

    /**
     * @return array<string, array{int, string, bool, int}>
     */
    public static function taxonomyProvider(): array
    {
        return [
            // label => [httpStatus, envelopeCode, expectedRetryable, queuedResponses]
            'invalid_input (400)' => [400, 'invalid_input', false, 1],
            'unauthorized (401)' => [401, 'unauthorized', false, 1],
            'forbidden (403)' => [403, 'forbidden', false, 1],
            'not_found (404)' => [404, 'not_found', false, 1],
            'source_asset_unavailable (422)' => [422, 'source_asset_unavailable', false, 1],
            'rate_limit_exceeded (429)' => [429, 'rate_limit_exceeded', true, 4],
            'internal_error (503)' => [503, 'internal_error', true, 4],
        ];
    }

    /**
     * @dataProvider taxonomyProvider
     */
    public function testErrorCodeMapsToPerItemOutcome(
        int $status,
        string $code,
        bool $expectedRetryable,
        int $queued
    ): void {
        $queue = array_fill(0, $queued, $this->errorResponse($status, $code));
        $client = $this->clientWith($queue);

        $result = $this->orchestrator($client)->ingest(
            new IngestItem(1, 42, 42, IngestItem::ACTION_PRODUCT)
        );

        self::assertTrue($result->isError(), 'expected an error result');
        self::assertSame($code, $result->errorCode);
        self::assertSame($expectedRetryable, $result->retryable);
        self::assertSame('msg for ' . $code, $result->errorMessage);
    }
}
