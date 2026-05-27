<?php

declare(strict_types=1);

namespace QameraAi\Module\Api;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;
use QameraAi\Module\Api\Dto\AiModel;
use QameraAi\Module\Api\Dto\AspectRatio;
use QameraAi\Module\Api\Dto\ImageResponse;
use QameraAi\Module\Api\Dto\JobDto;
use QameraAi\Module\Api\Dto\JobsListFilters;
use QameraAi\Module\Api\Dto\JobsListResponse;
use QameraAi\Module\Api\Dto\MeResponse;
use QameraAi\Module\Api\Dto\PackshotResponse;
use QameraAi\Module\Api\Dto\Preset;
use QameraAi\Module\Api\Dto\PresignedUploadResponse;
use QameraAi\Module\Api\Dto\Pricing;
use QameraAi\Module\Api\Dto\ProductDetailResponse;
use QameraAi\Module\Api\Dto\ProductsListFilters;
use QameraAi\Module\Api\Dto\ProductsListResponse;
use QameraAi\Module\Api\Dto\RegisterImageRequest;
use QameraAi\Module\Api\Dto\RegisterPackshotRequest;
use QameraAi\Module\Api\Dto\Scenery;
use QameraAi\Module\Api\Dto\SubmitJobRequest;
use QameraAi\Module\Api\Dto\SubmitJobResponse;
use QameraAi\Module\Api\Exception\ApiException;
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

/**
 * HTTP client to the Qamera AI Plugin API.
 *
 * One method per consumed endpoint. Authentication, retry, idempotency,
 * and error-envelope decoding are baked in — callers never see a raw
 * Guzzle response or exception.
 */
final class QameraApiClient
{
    /** Endpoints that MUST carry an `Idempotency-Key` header on write. */
    private const IDEMPOTENT_WRITE_PATHS = ['/jobs', '/images', '/packshots'];

    private readonly Client $httpClient;

    public function __construct(
        private readonly string $baseUrl,
        private readonly HeaderBuilder $headerBuilder,
        private readonly RetryDecider $retryDecider,
        private readonly ErrorEnvelopeParser $envelopeParser,
        private readonly IdempotencyKeyGenerator $keyGenerator,
        private readonly JsonDecoder $decoder,
        ?HandlerStack $handlerStack = null,
    ) {
        $stack = $handlerStack ?? HandlerStack::create();
        $stack->push(Middleware::retry(
            fn (int $retries, $request, $response, $exception): bool =>
                $this->retryDecider->shouldRetry($retries, $request, $response, $exception),
            fn (int $retries, ?ResponseInterface $response): int =>
                $this->retryDecider->delayMs($retries, $response),
        ));

        $this->httpClient = new Client([
            'handler' => $stack,
            'http_errors' => false,
            'connect_timeout' => 5.0,
            'timeout' => 30.0,
        ]);
    }

    public function me(): MeResponse
    {
        return $this->send('GET', '/me', null, MeResponse::class);
    }

    /**
     * @return AiModel[]
     */
    public function listAiModels(): array
    {
        return $this->sendList('GET', '/ai-models', 'ai_models', AiModel::class);
    }

    /**
     * @return Scenery[]
     */
    public function listSceneries(): array
    {
        return $this->sendList('GET', '/sceneries', 'sceneries', Scenery::class);
    }

    /**
     * @return Preset[]
     */
    public function listPresets(): array
    {
        return $this->sendList('GET', '/presets', 'presets', Preset::class);
    }

    /**
     * @return AspectRatio[]
     */
    public function listAspectRatios(): array
    {
        return $this->sendList('GET', '/aspect-ratios', 'aspect_ratios', AspectRatio::class);
    }

    public function getPricing(): Pricing
    {
        return $this->send('GET', '/pricing', null, Pricing::class);
    }

    public function registerImage(RegisterImageRequest $request): ImageResponse
    {
        $payload = $this->dispatch('POST', '/images', ['images' => [$request->toPayload()]]);

        return $this->unwrapSingleResult($payload, ImageResponse::class);
    }

    public function registerPackshot(RegisterPackshotRequest $request): PackshotResponse
    {
        $payload = $this->dispatch('POST', '/packshots', ['packshots' => [$request->toPayload()]]);

        return $this->unwrapSingleResult($payload, PackshotResponse::class);
    }

    /**
     * Request a presigned upload URL for one binary asset.
     *
     * @throws \InvalidArgumentException When `$filename` is empty / >256 chars,
     *                                   `$contentType` is empty, or `$sizeBytes`
     *                                   is non-positive / > 52428800 (50 MiB).
     *                                   Validation runs before any HTTP call.
     */
    public function requestUpload(string $filename, string $contentType, int $sizeBytes): PresignedUploadResponse
    {
        if ($filename === '' || strlen($filename) > 256) {
            throw new \InvalidArgumentException(
                'filename must be 1..256 characters'
            );
        }
        if ($contentType === '') {
            throw new \InvalidArgumentException('content_type must not be empty');
        }
        if ($sizeBytes <= 0 || $sizeBytes > 52428800) {
            throw new \InvalidArgumentException(
                'size_bytes must be a positive integer ≤ 52428800 (50 MiB)'
            );
        }

        return $this->send('POST', '/assets/upload', [
            'mode' => 'presigned',
            'filename' => $filename,
            'content_type' => $contentType,
            'size_bytes' => $sizeBytes,
        ], PresignedUploadResponse::class);
    }

    public function submitJob(SubmitJobRequest $request): SubmitJobResponse
    {
        return $this->send('POST', '/jobs', $request->toPayload(), SubmitJobResponse::class);
    }

    public function getJob(string $id): JobDto
    {
        return $this->send('GET', '/jobs/' . rawurlencode($id), null, JobDto::class);
    }

    public function listJobs(JobsListFilters $filters): JobsListResponse
    {
        $query = http_build_query($filters->toQuery());

        return $this->send('GET', '/jobs?' . $query, null, JobsListResponse::class);
    }

    public function listProducts(ProductsListFilters $filters): ProductsListResponse
    {
        $query = http_build_query($filters->toQuery());

        return $this->send('GET', '/products?' . $query, null, ProductsListResponse::class);
    }

    public function getProduct(string $idOrRef): ProductDetailResponse
    {
        return $this->send('GET', '/products/' . rawurlencode($idOrRef), null, ProductDetailResponse::class);
    }

    /**
     * Server response body (if any) is discarded — DELETE is best-effort
     * idempotent and callers only care whether a non-error status came back.
     */
    public function deleteProduct(string $idOrRef): void
    {
        $this->dispatch('DELETE', '/products/' . rawurlencode($idOrRef), null);
    }

    /**
     * @template T of object
     *
     * @param class-string<T>           $responseClass
     * @param array<string, mixed>|null $body
     *
     * @return T
     */
    private function send(string $method, string $path, ?array $body, string $responseClass): object
    {
        $payload = $this->dispatch($method, $path, $body);

        return $this->decoder->decode($responseClass, $payload);
    }

    /**
     * @template T of object
     *
     * @param class-string<T>      $elementClass
     * @param array<string, mixed> $payload
     *
     * @return T
     */
    private function unwrapSingleResult(array $payload, string $elementClass): object
    {
        $results = $payload['results'] ?? null;
        if (!is_array($results)) {
            throw ValidationException::malformedResponse('results');
        }
        if (count($results) !== 1) {
            throw ValidationException::unexpectedResultsSize(count($results), 1);
        }
        $first = $results[0];
        if (!is_array($first)) {
            throw ValidationException::malformedResponse('results[0]');
        }

        return $this->decoder->decode($elementClass, $first);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $elementClass
     *
     * @return T[]
     */
    private function sendList(string $method, string $path, string $wrapperKey, string $elementClass): array
    {
        $payload = $this->dispatch($method, $path, null);
        if (!array_key_exists($wrapperKey, $payload) || !is_array($payload[$wrapperKey])) {
            throw ValidationException::malformedResponse($wrapperKey);
        }

        $out = [];
        foreach ($payload[$wrapperKey] as $item) {
            if (!is_array($item)) {
                throw ValidationException::malformedResponse($wrapperKey . '[]');
            }
            $out[] = $this->decoder->decode($elementClass, $item);
        }

        return $out;
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function dispatch(string $method, string $path, ?array $body): array
    {
        $headers = $this->headerBuilder->baseHeaders();
        if ($method === 'POST' && $this->isIdempotentWritePath($path)) {
            $headers['Idempotency-Key'] = $this->keyGenerator->generate();
        }

        $encodedBody = null;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            try {
                $encodedBody = json_encode($body, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new ValidationException('Failed to encode request body: ' . $e->getMessage());
            }
        }

        $request = new Request($method, $this->baseUrl . $path, $headers, $encodedBody);

        try {
            $response = $this->httpClient->send($request);
        } catch (ConnectException $e) {
            throw new TransportException('Network failure calling Qamera AI: ' . $e->getMessage(), $e);
        } catch (GuzzleException $e) {
            throw new TransportException('HTTP transport error calling Qamera AI: ' . $e->getMessage(), $e);
        }

        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 300) {
            return $this->decodeJsonBody($response);
        }

        throw $this->mapErrorResponse($response);
    }

    private function isIdempotentWritePath(string $path): bool
    {
        $pathOnly = strtok($path, '?');
        if ($pathOnly === false) {
            return false;
        }

        return in_array($pathOnly, self::IDEMPOTENT_WRITE_PATHS, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonBody(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();
        if ($body === '') {
            return [];
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ValidationException('Malformed JSON in Qamera AI response: ' . $e->getMessage());
        }

        if (!is_array($decoded)) {
            throw new ValidationException('Qamera AI response root was not a JSON object');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function mapErrorResponse(ResponseInterface $response): ApiException
    {
        $status = $response->getStatusCode();
        $envelope = $this->envelopeParser->parse($response);
        $correlationId = $response->hasHeader('X-Correlation-Id')
            ? $response->getHeaderLine('X-Correlation-Id')
            : null;
        $message = $envelope?->messageFor('en') ?? sprintf('Qamera AI returned HTTP %d', $status);

        return match (true) {
            $status === 401 || $status === 403 => new AuthException($message, $status, $envelope, $correlationId),
            $status === 404 => new NotFoundException($message, $status, $envelope, $correlationId),
            $status === 429 => new RateLimitException(
                $message,
                (int) $response->getHeaderLine('Retry-After'),
                $envelope,
                $correlationId,
            ),
            $status === 400 || $status === 409 || $status === 422 => new ValidationException(
                $message,
                $status,
                $envelope,
                $correlationId,
            ),
            $status >= 500 && $status < 600 => new ServerException($message, $status, $envelope, $correlationId),
            default => new ServerException($message, $status, $envelope, $correlationId),
        };
    }
}
