<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Api;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use QameraAi\Module\Api\Dto\ImageResponse;
use QameraAi\Module\Api\Dto\JobsListFilters;
use QameraAi\Module\Api\Dto\PackshotResponse;
use QameraAi\Module\Api\Dto\ProductMetadata;
use QameraAi\Module\Api\Dto\ProductsListFilters;
use QameraAi\Module\Api\Dto\RegisterImageRequest;
use QameraAi\Module\Api\Dto\RegisterPackshotRequest;
use QameraAi\Module\Api\Dto\SessionConfig;
use QameraAi\Module\Api\Dto\Subject;
use QameraAi\Module\Api\Dto\SubmitJobRequest;
use QameraAi\Module\Api\Exception\AuthException;
use QameraAi\Module\Api\Exception\NotFoundException;
use QameraAi\Module\Api\Exception\RateLimitException;
use QameraAi\Module\Api\Exception\ServerException;
use QameraAi\Module\Api\Exception\TransportException;
use QameraAi\Module\Api\Exception\ValidationException;
use QameraAi\Module\Api\Internal\ErrorEnvelopeParser;
use QameraAi\Module\Api\Internal\HeaderBuilder;
use QameraAi\Module\Api\Internal\IdempotencyKeyGenerator;
use QameraAi\Module\Api\Internal\JsonDecoder;
use QameraAi\Module\Api\Internal\RetryDecider;
use QameraAi\Module\Api\QameraApiClient;
use stdClass;

final class QameraApiClientTest extends TestCase
{
    private const BASE_URL = 'https://qamera.test/api/v1/plugin';

    private stdClass $recorder;

    /**
     * Builds a client with a MockHandler-backed Guzzle stack. Records every
     * dispatched request via the RetryDecider (which Guzzle invokes once per
     * attempt); `delayMs` is forced to 0 so the suite never sleeps.
     *
     * @param list<Response|\Throwable> $queue
     */
    private function clientWith(array $queue, ?IdempotencyKeyGenerator $keyGen = null): QameraApiClient
    {
        $mock = new MockHandler($queue);
        $stack = HandlerStack::create($mock);

        $this->recorder = new stdClass();
        $this->recorder->records = [];
        $recorder = $this->recorder;
        $trackingDecider = new class ($recorder) extends RetryDecider {
            public function __construct(private readonly stdClass $recorder)
            {
            }

            public function shouldRetry(
                int $retries,
                RequestInterface $request,
                ?ResponseInterface $response,
                ?\Throwable $exception,
            ): bool {
                $this->recorder->records[] = [
                    'request' => $request,
                    'response' => $response,
                    'exception' => $exception,
                ];

                return parent::shouldRetry($retries, $request, $response, $exception);
            }

            public function delayMs(int $retries, ?ResponseInterface $response): int
            {
                return 0;
            }
        };

        return new QameraApiClient(
            self::BASE_URL,
            new HeaderBuilder('api_key_dummy', 'QameraAi-PrestaShop-Module/1.0.0 (9.0.0)', 'en'),
            $trackingDecider,
            new ErrorEnvelopeParser(),
            $keyGen ?? new IdempotencyKeyGenerator(),
            new JsonDecoder(),
            $stack,
        );
    }

    private function meResponseBody(): string
    {
        return (string) json_encode([
            'account_id' => 'acct_123',
            'account_name' => 'Pracownia Qamery AI',
            'account_slug' => 'pracownia-qamery-ai',
            'credits_balance' => 1500,
            'subscription_plan' => 'pro',
            'rate_limit_per_min' => 60,
            'installation' => [
                'id' => 'i1',
                'platform' => 'prestashop',
                'status' => 'active',
                'scopes' => ['plugin.assets:upload'],
            ],
            'data_processors' => [],
        ]);
    }

    /** A single-image bulk-results response used in idempotency tests. */
    private function imagesBulkOneResponseBody(): string
    {
        return (string) json_encode([
            'results' => [
                [
                    'external_ref' => 'ext-1',
                    'product_id' => '33333333-3333-3333-3333-333333333333',
                    'image_id' => '44444444-4444-4444-4444-444444444444',
                    'status' => 'created',
                ],
            ],
        ]);
    }

    private function registerImageRequest(): RegisterImageRequest
    {
        return new RegisterImageRequest(
            'ext-1',
            'ps:1:42',
            '11111111-1111-1111-1111-111111111111',
        );
    }

    private function lastRequestBody(int $index = 0): array
    {
        $body = (string) $this->recorder->records[$index]['request']->getBody();
        $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    public function testMeHappyPath(): void
    {
        $client = $this->clientWith([
            new Response(200, ['Content-Type' => 'application/json'], $this->meResponseBody()),
        ]);

        $me = $client->me();

        self::assertSame('Pracownia Qamery AI', $me->accountName);
        self::assertSame('prestashop', $me->installation->platform);
        self::assertSame(['plugin.assets:upload'], $me->installation->scopes);
        self::assertCount(1, $this->recorder->records);
        self::assertSame('api_key_dummy', $this->recorder->records[0]['request']->getHeaderLine('X-Api-Key'));
        self::assertSame('GET', $this->recorder->records[0]['request']->getMethod());
        self::assertSame(self::BASE_URL . '/me', (string) $this->recorder->records[0]['request']->getUri());
        self::assertSame('', $this->recorder->records[0]['request']->getHeaderLine('Idempotency-Key'));
    }

    public function testRetryOn503ThenSuccess(): void
    {
        $client = $this->clientWith([
            new Response(503, [], json_encode(['error' => ['code' => 'service_unavailable']])),
            new Response(200, [], $this->meResponseBody()),
        ]);

        $me = $client->me();

        self::assertSame('acct_123', $me->accountId);
        self::assertCount(2, $this->recorder->records);
    }

    public function testFourAttemptCapRaisesServerException(): void
    {
        $client = $this->clientWith([
            new Response(503, [], json_encode(['error' => ['code' => 'service_unavailable']])),
            new Response(503, [], json_encode(['error' => ['code' => 'service_unavailable']])),
            new Response(503, [], json_encode(['error' => ['code' => 'service_unavailable']])),
            new Response(503, [], json_encode(['error' => ['code' => 'service_unavailable']])),
        ]);

        try {
            $client->me();
            self::fail('Expected ServerException');
        } catch (ServerException $e) {
            self::assertSame(503, $e->getStatusCode());
            self::assertNotNull($e->getEnvelope());
            self::assertSame('service_unavailable', $e->getEnvelope()->code);
            self::assertCount(4, $this->recorder->records);
        }
    }

    public function testIdempotencyKeyStableAcrossRetries(): void
    {
        $client = $this->clientWith([
            new Response(503),
            new Response(503),
            new Response(503),
            new Response(200, [], $this->imagesBulkOneResponseBody()),
        ]);

        $client->registerImage($this->registerImageRequest());

        self::assertCount(4, $this->recorder->records);
        $first = $this->recorder->records[0]['request']->getHeaderLine('Idempotency-Key');
        self::assertNotSame('', $first);
        foreach ($this->recorder->records as $entry) {
            self::assertSame($first, $entry['request']->getHeaderLine('Idempotency-Key'));
        }
    }

    public function testDistinctIdempotencyKeysAcrossDistinctCalls(): void
    {
        $client = $this->clientWith([
            new Response(200, [], $this->imagesBulkOneResponseBody()),
            new Response(200, [], $this->imagesBulkOneResponseBody()),
        ]);

        $client->registerImage($this->registerImageRequest());
        $client->registerImage($this->registerImageRequest());

        self::assertCount(2, $this->recorder->records);
        $k1 = $this->recorder->records[0]['request']->getHeaderLine('Idempotency-Key');
        $k2 = $this->recorder->records[1]['request']->getHeaderLine('Idempotency-Key');
        self::assertNotSame('', $k1);
        self::assertNotSame('', $k2);
        self::assertNotSame($k1, $k2);
    }

    public function testGetCarriesNoIdempotencyKey(): void
    {
        $client = $this->clientWith([new Response(200, [], $this->meResponseBody())]);
        $client->me();
        self::assertSame('', $this->recorder->records[0]['request']->getHeaderLine('Idempotency-Key'));
    }

    public function testAuthExceptionOn401(): void
    {
        $client = $this->clientWith([
            new Response(401, [], json_encode([
                'error' => ['code' => 'invalid_api_key', 'message_i18n' => ['en' => 'bad key']],
            ])),
        ]);

        try {
            $client->me();
            self::fail('expected AuthException');
        } catch (AuthException $e) {
            self::assertSame(401, $e->getStatusCode());
            self::assertSame('invalid_api_key', $e->getEnvelope()->code);
        }
    }

    public function testNotFoundOn404(): void
    {
        $client = $this->clientWith([
            new Response(404, [], json_encode(['error' => ['code' => 'not_found']])),
        ]);

        $this->expectException(NotFoundException::class);
        $client->getProduct('missing');
    }

    public function testValidationOn422(): void
    {
        $client = $this->clientWith([
            new Response(422, [], json_encode([
                'error' => ['code' => 'invalid_aspect_ratio', 'message_i18n' => ['en' => 'bad ratio']],
            ])),
        ]);

        try {
            $client->registerImage($this->registerImageRequest());
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame('invalid_aspect_ratio', $e->getEnvelope()->code);
        }
    }

    public function testRateLimitOn429AfterRetriesExhausted(): void
    {
        $headers = ['Retry-After' => '5'];
        $body = (string) json_encode(['error' => ['code' => 'rate_limited']]);
        $client = $this->clientWith([
            new Response(429, $headers, $body),
            new Response(429, $headers, $body),
            new Response(429, $headers, $body),
            new Response(429, $headers, $body),
        ]);

        try {
            $client->me();
            self::fail('expected RateLimitException');
        } catch (RateLimitException $e) {
            self::assertSame(5, $e->getRetryAfter());
            self::assertCount(4, $this->recorder->records);
        }
    }

    public function testConnectExceptionRaisesTransportException(): void
    {
        $req = new Request('GET', self::BASE_URL . '/me');
        $client = $this->clientWith([
            new ConnectException('dns fail', $req),
            new ConnectException('dns fail', $req),
            new ConnectException('dns fail', $req),
            new ConnectException('dns fail', $req),
        ]);

        $this->expectException(TransportException::class);
        $client->me();
    }

    /* ----------------------------------------------------------------------
     * §3-4 — requestUpload
     * -------------------------------------------------------------------- */

    public function testRequestUploadSerializesPresignedMode(): void
    {
        $client = $this->clientWith([
            new Response(200, [], (string) json_encode([
                'asset_id' => 'uuid-1',
                'bucket' => 'plugin_assets',
                'storage_path' => 'acct/inst/asset/cover.jpg',
                'upload_url' => 'https://upload',
                'upload_token' => 'tok',
                'expires_at' => '2026-05-27T14:00:00Z',
            ])),
        ]);

        $res = $client->requestUpload('cover.jpg', 'image/jpeg', 12345);

        self::assertSame('uuid-1', $res->assetId);
        self::assertSame('https://upload', $res->uploadUrl);
        self::assertSame([
            'mode' => 'presigned',
            'filename' => 'cover.jpg',
            'content_type' => 'image/jpeg',
            'size_bytes' => 12345,
        ], $this->lastRequestBody());
    }

    public function testRequestUploadHandlesNullableUploadFieldsAsMultipartShape(): void
    {
        $client = $this->clientWith([
            new Response(200, [], (string) json_encode([
                'asset_id' => 'uuid-2',
                'bucket' => 'plugin_assets',
                'storage_path' => 'acct/inst/asset/x.jpg',
                'upload_url' => null,
                'upload_token' => null,
                'expires_at' => null,
            ])),
        ]);

        $res = $client->requestUpload('x.jpg', 'image/jpeg', 100);

        self::assertNull($res->uploadUrl);
        self::assertNull($res->uploadToken);
        self::assertNull($res->expiresAt);
    }

    public function testRequestUploadRejectsEmptyFilenameBeforeHttpCall(): void
    {
        $client = $this->clientWith([]);
        $this->expectException(\InvalidArgumentException::class);
        $client->requestUpload('', 'image/jpeg', 100);
    }

    public function testRequestUploadRejectsOversizeFilename(): void
    {
        $client = $this->clientWith([]);
        $this->expectException(\InvalidArgumentException::class);
        $client->requestUpload(str_repeat('a', 257), 'image/jpeg', 100);
    }

    public function testRequestUploadRejectsEmptyContentType(): void
    {
        $client = $this->clientWith([]);
        $this->expectException(\InvalidArgumentException::class);
        $client->requestUpload('cover.jpg', '', 100);
    }

    public function testRequestUploadRejectsNonPositiveSize(): void
    {
        $client = $this->clientWith([]);
        $this->expectException(\InvalidArgumentException::class);
        $client->requestUpload('cover.jpg', 'image/jpeg', 0);
    }

    public function testRequestUploadRejectsNegativeSize(): void
    {
        $client = $this->clientWith([]);
        $this->expectException(\InvalidArgumentException::class);
        $client->requestUpload('cover.jpg', 'image/jpeg', -1);
    }

    public function testRequestUploadRejectsOversize(): void
    {
        $client = $this->clientWith([]);
        $this->expectException(\InvalidArgumentException::class);
        $client->requestUpload('cover.jpg', 'image/jpeg', 52428801);
    }

    /* ----------------------------------------------------------------------
     * §5-6 — registerImage
     * -------------------------------------------------------------------- */

    public function testRegisterImageWrapsSingleInBulkArray(): void
    {
        $client = $this->clientWith([new Response(200, [], $this->imagesBulkOneResponseBody())]);
        $client->registerImage(new RegisterImageRequest('ext-1', 'ps:1:42', 'asset-uuid'));

        $body = $this->lastRequestBody();
        self::assertSame([
            'images' => [
                [
                    'external_ref' => 'ext-1',
                    'product_ref' => 'ps:1:42',
                    'asset_id' => 'asset-uuid',
                ],
            ],
        ], $body);
    }

    public function testRegisterImageIncludesProductMetadataWhenSet(): void
    {
        $client = $this->clientWith([new Response(200, [], $this->imagesBulkOneResponseBody())]);
        $client->registerImage(new RegisterImageRequest(
            'ext-1',
            'ps:1:42',
            'asset-uuid',
            new ProductMetadata('Widget', 'WDG-001', 'desc'),
        ));

        $body = $this->lastRequestBody();
        self::assertSame([
            'display_name' => 'Widget',
            'sku' => 'WDG-001',
            'description' => 'desc',
        ], $body['images'][0]['product_metadata']);
    }

    public function testRegisterImageUnwrapsBulkResponse(): void
    {
        $client = $this->clientWith([new Response(200, [], $this->imagesBulkOneResponseBody())]);
        $res = $client->registerImage($this->registerImageRequest());
        self::assertInstanceOf(ImageResponse::class, $res);
        self::assertSame('created', $res->status);
        self::assertSame('ext-1', $res->externalRef);
    }

    public function testRegisterImageEmptyResultsArrayThrowsValidationException(): void
    {
        $client = $this->clientWith([new Response(200, [], (string) json_encode(['results' => []]))]);

        try {
            $client->registerImage($this->registerImageRequest());
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertStringContainsString('unexpected results size: 0, expected 1', $e->getMessage());
        }
    }

    public function testRegisterImageMultipleResultsAlsoThrowsValidationException(): void
    {
        $body = (string) json_encode([
            'results' => [
                ['external_ref' => 'a', 'product_id' => 'pid', 'image_id' => 'iid', 'status' => 'created'],
                ['external_ref' => 'b', 'product_id' => 'pid', 'image_id' => 'iid2', 'status' => 'created'],
            ],
        ]);
        $client = $this->clientWith([new Response(200, [], $body)]);

        try {
            $client->registerImage($this->registerImageRequest());
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertStringContainsString('unexpected results size: 2, expected 1', $e->getMessage());
        }
    }

    /* ----------------------------------------------------------------------
     * §7-8 — registerPackshot (symmetric)
     * -------------------------------------------------------------------- */

    public function testRegisterPackshotWrapsSingleInBulkArray(): void
    {
        $okBody = (string) json_encode([
            'results' => [
                ['external_ref' => 'pk', 'product_id' => 'pid', 'packshot_id' => 'pkid', 'status' => 'created'],
            ],
        ]);
        $client = $this->clientWith([new Response(200, [], $okBody)]);
        $res = $client->registerPackshot(new RegisterPackshotRequest('pk', 'ps:1:42', 'asset-uuid'));

        $body = $this->lastRequestBody();
        self::assertSame([
            'packshots' => [
                ['external_ref' => 'pk', 'product_ref' => 'ps:1:42', 'asset_id' => 'asset-uuid'],
            ],
        ], $body);
        self::assertInstanceOf(PackshotResponse::class, $res);
        self::assertSame('created', $res->status);
    }

    public function testRegisterPackshotSerializesSourceImageRefWhenSet(): void
    {
        $okBody = (string) json_encode([
            'results' => [
                ['external_ref' => 'pk', 'product_id' => 'pid', 'packshot_id' => 'pkid', 'status' => 'created'],
            ],
        ]);
        $client = $this->clientWith([new Response(200, [], $okBody)]);
        $client->registerPackshot(new RegisterPackshotRequest(
            'pk',
            'ps:1:42',
            'asset-uuid',
            null,
            'img-ref-1'
        ));

        $body = $this->lastRequestBody();
        self::assertSame('img-ref-1', $body['packshots'][0]['source_image_ref']);
    }

    public function testRegisterPackshotEmptyResultsThrows(): void
    {
        $client = $this->clientWith([new Response(200, [], (string) json_encode(['results' => []]))]);
        $this->expectException(ValidationException::class);
        $client->registerPackshot(new RegisterPackshotRequest('pk', 'ps:1:42', 'asset-uuid'));
    }

    /* ----------------------------------------------------------------------
     * §9 — sendList wrapper-key handling
     * -------------------------------------------------------------------- */

    public function testListAiModelsDecodesWrapperKey(): void
    {
        $body = (string) json_encode([
            'ai_models' => [[
                'id' => 'openai/gpt-image-1',
                'provider' => 'openai',
                'model' => 'gpt-image-1',
                'output_type' => 'image',
                'supported_aspect_ratios' => ['1:1', '4:5'],
                'base_credit_cost' => 5,
            ]],
        ]);
        $client = $this->clientWith([new Response(200, [], $body)]);

        $models = $client->listAiModels();
        self::assertCount(1, $models);
        self::assertSame('openai', $models[0]->provider);
        self::assertSame(['1:1', '4:5'], $models[0]->supportedAspectRatios);
    }

    public function testListAiModelsMissingWrapperKeyThrowsMalformedResponse(): void
    {
        $body = (string) json_encode(['items' => []]);
        $client = $this->clientWith([new Response(200, [], $body)]);

        try {
            $client->listAiModels();
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertStringContainsString('ai_models', $e->getMessage());
        }
    }

    public function testListSceneriesHandlesNullableFields(): void
    {
        $body = (string) json_encode([
            'sceneries' => [[
                'id' => 's1',
                'name' => 'S1',
                'thumbnail' => null,
                'voting' => null,
                'status' => null,
                'source' => 'account',
                'created_at' => '2026-05-01T00:00:00Z',
            ]],
        ]);
        $client = $this->clientWith([new Response(200, [], $body)]);

        $list = $client->listSceneries();
        self::assertNull($list[0]->thumbnail);
    }

    /* ----------------------------------------------------------------------
     * §10.5 — getPricing list-with-currency
     * -------------------------------------------------------------------- */

    public function testGetPricingReturnsListWithCurrency(): void
    {
        $body = (string) json_encode([
            'pricing' => [
                ['job_type' => 'packshot', 'provider' => 'openai', 'model' => 'gpt-image-1', 'credit_cost' => 5],
                ['job_type' => 'image', 'provider' => 'openai', 'model' => 'gpt-image-1', 'credit_cost' => 3],
            ],
            'currency' => 'credits',
        ]);
        $client = $this->clientWith([new Response(200, [], $body)]);

        $pricing = $client->getPricing();
        self::assertCount(2, $pricing->pricing);
        self::assertSame('credits', $pricing->currency);
        self::assertSame('packshot', $pricing->pricing[0]->jobType);
        self::assertSame(5, $pricing->pricing[0]->creditCost);
    }

    /* ----------------------------------------------------------------------
     * §11 — submitJob session-lifecycle shape
     * -------------------------------------------------------------------- */

    public function testSubmitJobSerializesNestedShape(): void
    {
        $body = (string) json_encode([
            'order_id' => '99999999-9999-9999-9999-999999999999',
            'status' => 'in_progress',
            'subjects' => [
                ['product_ref' => 'ps:1:42', 'job_ids' => ['j1', 'j2']],
            ],
        ]);
        $client = $this->clientWith([new Response(200, [], $body)]);

        $req = new SubmitJobRequest(
            new SessionConfig('4:5', null, null, 'preset-1'),
            [new Subject('asset-1', 'Widget', 'ps:1:42', 2, 'openai/gpt-image-1')],
            null,
            null,
            10,
        );
        $res = $client->submitJob($req);

        self::assertSame('99999999-9999-9999-9999-999999999999', $res->orderId);
        self::assertSame('in_progress', $res->status);
        self::assertCount(2, $res->subjects[0]->jobIds);

        $dispatched = $this->lastRequestBody();
        self::assertSame('4:5', $dispatched['session_config']['aspect_ratio']);
        self::assertSame('preset-1', $dispatched['session_config']['preset_id']);
        self::assertSame('ps:1:42', $dispatched['subjects'][0]['product_ref']);
        self::assertSame(2, $dispatched['subjects'][0]['images_count']);
        self::assertSame('openai/gpt-image-1', $dispatched['subjects'][0]['ai_model']);
        self::assertSame(10, $dispatched['priority']);
    }

    public function testSubjectRejectsBadAiModelShape(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Subject('asset-1', 'Widget', 'ps:1:42', 1, 'no-slash');
    }

    public function testSubmitJobRejectsEmptySubjects(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SubmitJobRequest(new SessionConfig('4:5'), []);
    }

    /* ----------------------------------------------------------------------
     * §12 — getJob / listJobs JobDto
     * -------------------------------------------------------------------- */

    public function testGetJobReturnsOutputsAsObjects(): void
    {
        $body = (string) json_encode($this->jobDtoArray());
        $client = $this->clientWith([new Response(200, [], $body)]);

        $job = $client->getJob('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa');

        self::assertSame('completed', $job->status);
        self::assertCount(1, $job->outputs);
        self::assertSame(1024, $job->outputs[0]->width);
    }

    public function testListJobsUsesJobsWrapperAndNextCursor(): void
    {
        $body = (string) json_encode([
            'jobs' => [$this->jobDtoArray()],
            'next_cursor' => 'cursor-1',
        ]);
        $client = $this->clientWith([new Response(200, [], $body)]);

        $list = $client->listJobs(new JobsListFilters(status: 'completed', limit: 50));
        self::assertCount(1, $list->jobs);
        self::assertSame('cursor-1', $list->nextCursor);

        $uri = (string) $this->recorder->records[0]['request']->getUri();
        self::assertStringContainsString('status=completed', $uri);
        self::assertStringContainsString('limit=50', $uri);
    }

    /**
     * @return array<string, mixed>
     */
    private function jobDtoArray(): array
    {
        return [
            'id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            'order_id' => '99999999-9999-9999-9999-999999999999',
            'status' => 'completed',
            'job_type' => 'packshot',
            'provider' => 'openai',
            'model' => 'gpt-image-1',
            'unit_cost' => 5,
            'attempt_count' => 1,
            'outputs' => [
                [
                    'url' => 'https://x/1.jpg',
                    'type' => 'image',
                    'width' => 1024,
                    'height' => 1280,
                    'size_bytes' => 1024,
                ],
            ],
            'error' => null,
            'external_metadata' => null,
            'packshot_asset_id' => '55555555-5555-5555-5555-555555555555',
            'product_label' => 'Widget',
            'product_ref' => 'ps:1:42',
            'voting' => null,
            'voting_at' => null,
            'created_at' => '2026-05-27T10:00:00Z',
            'updated_at' => '2026-05-27T10:02:30Z',
            'completed_at' => '2026-05-27T10:02:30Z',
        ];
    }

    /* ----------------------------------------------------------------------
     * §13 — listProducts / getProduct
     * -------------------------------------------------------------------- */

    public function testListProductsSerializesIncludeDeletedAsStringLiteral(): void
    {
        $body = (string) json_encode([
            'items' => [],
            'next_cursor' => null,
        ]);
        $client = $this->clientWith([new Response(200, [], $body)]);
        $client->listProducts(new ProductsListFilters(includeDeleted: true, limit: 5));

        $uri = (string) $this->recorder->records[0]['request']->getUri();
        self::assertStringContainsString('include_deleted=true', $uri);
        self::assertStringContainsString('limit=5', $uri);
    }

    public function testGetProductReturnsDetailWithEmbeddedImagesAndPackshots(): void
    {
        $body = (string) json_encode([
            'id' => '33333333-3333-3333-3333-333333333333',
            'external_ref' => 'ps:1:42',
            'display_name' => 'Widget',
            'sku' => null,
            'description' => null,
            'source_metadata' => [],
            'deleted_at' => null,
            'created_at' => 'now',
            'updated_at' => 'now',
            'images' => [[
                'id' => 'i1',
                'external_ref' => null,
                'product_id' => '33333333-3333-3333-3333-333333333333',
                'asset_id' => 'a1',
                'byte_size' => 100,
                'content_type' => 'image/jpeg',
                'width' => null,
                'height' => null,
                'sha256' => str_repeat('a', 64),
                'created_at' => 'now',
            ]],
            'images_truncated' => false,
            'packshots' => [],
            'packshots_truncated' => false,
        ]);
        $client = $this->clientWith([new Response(200, [], $body)]);

        $product = $client->getProduct('ps:1:42');
        self::assertSame('Widget', $product->displayName);
        self::assertCount(1, $product->images);
        self::assertSame('a1', $product->images[0]->assetId);
    }

    public function testDeleteProductDiscardsResponseBody(): void
    {
        $client = $this->clientWith([new Response(204)]);
        $client->deleteProduct('ps:1:42');
        self::assertCount(1, $this->recorder->records);
        self::assertSame('DELETE', $this->recorder->records[0]['request']->getMethod());
    }
}
