<?php

declare(strict_types=1);

namespace QameraAi\Module\Gallery;

use QameraAi\Module\Api\Dto\RegisterImageRequest;
use QameraAi\Module\Api\Dto\RegisterPackshotRequest;
use QameraAi\Module\Api\Exception\ApiException;
use QameraAi\Module\Api\Exception\AuthException;
use QameraAi\Module\Api\Exception\NotFoundException;
use QameraAi\Module\Api\Exception\RateLimitException;
use QameraAi\Module\Api\Exception\ServerException;
use QameraAi\Module\Api\Exception\TransportException;
use QameraAi\Module\Api\Exception\ValidationException;
use QameraAi\Module\Api\QameraApiClient;
use QameraAi\Module\Sync\ExternalRefBuilder;
use QameraAi\Module\Sync\ImageUploadStrategy;
use QameraAi\Module\Sync\ProductRefBuilder;
use Throwable;

/**
 * Pushes a single PrestaShop gallery image into Qamera (design D2/D3):
 *
 *   "Add as product"  → resolve file → presigned upload → registerImage.
 *   "Add as packshot"  → the same, then registerPackshot with
 *                        `source_image_ref` = the image's external_ref, so
 *                        the packshot always lands with a non-null
 *                        source_image_id (sidesteps the null-source trap).
 *
 * Bytes are read and PUT server-side; the operator's browser never carries
 * them. Errors are mapped to a per-item taxonomy: `invalid_input`,
 * `unauthorized`, `forbidden`, `not_found`, `source_asset_unavailable` are
 * non-retryable; `rate_limit_exceeded` and `internal_error` (and transport
 * failures) are retried with backoff, reusing the deterministic
 * `external_ref` so retries can never duplicate.
 */
final class GalleryIngestOrchestrator
{
    /** Codes the operator can retry; everything else is terminal per item. */
    private const RETRYABLE_CODES = ['rate_limit_exceeded', 'internal_error'];

    public function __construct(
        private readonly ProductImageFileResolver $fileResolver,
        private readonly ImageUploadStrategy $uploadStrategy,
        private readonly QameraApiClient $apiClient,
        private readonly ExternalRefBuilder $refBuilder,
        private readonly ProductRefBuilder $productRefBuilder,
        private readonly WriteScopeChecker $scopeChecker,
        private readonly int $maxUploadBytes = 25_000_000,
        private readonly int $maxRetries = 2,
        private readonly int $retryDelayMs = 250,
        private readonly string $locale = 'en',
    ) {
    }

    public function ingest(IngestItem $item): IngestResult
    {
        if (!$this->scopeChecker->hasWriteScope()) {
            return IngestResult::error(
                'forbidden',
                'This installation lacks the plugin.catalog:write scope required to ingest images.',
                false
            );
        }

        try {
            $file = $this->fileResolver->resolve($item->psImageId);
        } catch (Throwable $e) {
            return IngestResult::error(
                'not_found',
                'Could not locate the gallery image file: ' . $e->getMessage(),
                false
            );
        }

        if ($file->sizeBytes > $this->maxUploadBytes) {
            return IngestResult::error(
                'invalid_input',
                sprintf(
                    'Image is %d bytes, exceeding the %d-byte upload limit.',
                    $file->sizeBytes,
                    $this->maxUploadBytes
                ),
                false
            );
        }

        $imageRef = $this->refBuilder->imageRef($item->idShop, $item->idProduct, $item->psImageId);
        $productRef = $this->productRefBuilder->build($item->idShop, $item->idProduct);

        try {
            $assetId = $this->withRetry(fn (): string => $this->uploadStrategy->uploadImage(
                $file->path,
                basename($file->path),
                $file->contentType,
                $file->sizeBytes
            ));

            $imageResponse = $this->withRetry(fn () => $this->apiClient->registerImage(
                new RegisterImageRequest($imageRef, $productRef, $assetId, $item->metadata)
            ));
        } catch (Throwable $e) {
            return $this->mapError($e);
        }

        if (!$item->isPackshot()) {
            return IngestResult::registered($imageResponse->status, $imageRef, null, $assetId);
        }

        $packshotRef = $this->refBuilder->packshotRef($item->idShop, $item->idProduct, $item->psImageId);

        try {
            $packshotResponse = $this->withRetry(fn () => $this->apiClient->registerPackshot(
                new RegisterPackshotRequest(
                    $packshotRef,
                    $productRef,
                    $assetId,
                    $item->metadata,
                    $imageRef
                )
            ));
        } catch (Throwable $e) {
            return $this->mapError($e);
        }

        return IngestResult::registered($packshotResponse->status, $imageRef, $packshotRef, $assetId);
    }

    /**
     * Runs $fn, retrying only transport / rate-limit / server errors with a
     * linear backoff. Non-retryable upstream errors bubble immediately.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    private function withRetry(callable $fn)
    {
        $attempt = 0;
        while (true) {
            try {
                return $fn();
            } catch (RateLimitException | ServerException | TransportException $e) {
                if ($attempt >= $this->maxRetries) {
                    throw $e;
                }
                $this->backoff(++$attempt);
            }
        }
    }

    protected function backoff(int $attempt): void
    {
        $delay = $this->retryDelayMs * $attempt;
        if ($delay > 0) {
            usleep($delay * 1000);
        }
    }

    private function mapError(Throwable $e): IngestResult
    {
        $envelope = $e instanceof ApiException ? $e->getEnvelope() : null;
        $code = $envelope?->code ?? $this->codeForException($e);
        $message = $envelope !== null
            ? $envelope->messageFor($this->locale)
            : $e->getMessage();

        $retryable = in_array($code, self::RETRYABLE_CODES, true)
            || $e instanceof TransportException;

        return IngestResult::error($code, $message, $retryable);
    }

    private function codeForException(Throwable $e): string
    {
        return match (true) {
            $e instanceof ValidationException => 'invalid_input',
            $e instanceof AuthException => 'forbidden',
            $e instanceof NotFoundException => 'not_found',
            $e instanceof RateLimitException => 'rate_limit_exceeded',
            $e instanceof ServerException => 'internal_error',
            $e instanceof TransportException => 'internal_error',
            default => 'internal_error',
        };
    }
}
