<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Api;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use stdClass;
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

final class QameraApiClientTest extends TestCase
{
    private const BASE_URL = 'https://qamera.test/api/v1/plugin';

    private stdClass $recorder;

    /**
     * Builds a client with a MockHandler-backed Guzzle stack. Records
     * every dispatched request via the RetryDecider (which Guzzle invokes
     * exactly once per attempt with the originating request); this avoids
     * a Guzzle stack-ordering quirk where a middleware pushed BEFORE the
     * retry middleware ends up OUTSIDE the retry wrapper and therefore
     * fires only once per outer send call. The decider's `delayMs` is
     * forced to 0 so the suite never actually sleeps between retries.
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
            new HeaderBuilder('mk_live_test', 'QameraAi-PrestaShop-Module/1.0.0 (9.0.0)', 'en'),
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
            'installation' => ['id' => 'i1', 'platform' => 'prestashop', 'status' => 'active'],
            'data_processors' => [],
        ]);
    }

    public function testMeHappyPath(): void
    {
        $client = $this->clientWith([
            new Response(200, ['Content-Type' => 'application/json'], $this->meResponseBody()),
        ]);

        $me = $client->me();

        self::assertSame('Pracownia Qamery AI', $me->accountName);
        self::assertSame('prestashop', $me->installation->platform);
        self::assertCount(1, $this->recorder->records);
        self::assertSame('mk_live_test', $this->recorder->records[0]['request']->getHeaderLine('X-Api-Key'));
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
            new Response(200, [], json_encode([
                'id' => 'job_1',
                'product_ref' => 'p1',
                'status' => 'queued',
                'result_urls' => [],
            ])),
        ]);

        $client->submitJob(new SubmitJobRequest('p1', 'preset', 'square', ['img_1']));

        self::assertCount(4, $this->recorder->records);
        $first = $this->recorder->records[0]['request']->getHeaderLine('Idempotency-Key');
        self::assertNotSame('', $first);
        foreach ($this->recorder->records as $entry) {
            self::assertSame($first, $entry['request']->getHeaderLine('Idempotency-Key'));
        }
    }

    public function testDistinctIdempotencyKeysAcrossDistinctCalls(): void
    {
        $body = (string) json_encode([
            'id' => 'job_1',
            'product_ref' => 'p1',
            'status' => 'queued',
            'result_urls' => [],
        ]);

        $client = $this->clientWith([
            new Response(200, [], $body),
            new Response(200, [], $body),
        ]);

        $client->submitJob(new SubmitJobRequest('p1', 'preset', 'square', ['img_1']));
        $client->submitJob(new SubmitJobRequest('p1', 'preset', 'square', ['img_1']));

        self::assertCount(2, $this->recorder->records);
        $k1 = $this->recorder->records[0]['request']->getHeaderLine('Idempotency-Key');
        $k2 = $this->recorder->records[1]['request']->getHeaderLine('Idempotency-Key');
        self::assertNotSame('', $k1);
        self::assertNotSame('', $k2);
        self::assertNotSame($k1, $k2);
    }

    public function testGetCarriesNoIdempotencyKey(): void
    {
        $client = $this->clientWith([
            new Response(200, [], $this->meResponseBody()),
        ]);

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
            $client->submitJob(new SubmitJobRequest('p1', 'preset', 'square', ['img_1']));
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
}
