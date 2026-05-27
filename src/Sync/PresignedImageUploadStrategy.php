<?php

declare(strict_types=1);

namespace QameraAi\Module\Sync;

use DateTimeImmutable;
use Exception;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use QameraAi\Module\Api\Dto\PresignedUploadResponse;
use QameraAi\Module\Api\Exception\TransportException;
use QameraAi\Module\Api\QameraApiClient;

/**
 * Uploads image bytes via the presigned-URL flow:
 *   1. Ask upstream for a `PresignedUploadResponse` (`POST /assets/upload`,
 *      with filename / content-type / size metadata required by the
 *      regenerated client surface).
 *   2. Refresh once if the URL has already expired (clock drift).
 *   3. PUT the local file's bytes to `uploadUrl`.
 *   4. Return the opaque `assetId` for the caller to use as `assetId`
 *      on the subsequent `registerImage` call.
 *
 * The PUT goes through a separate Guzzle client so headers / timeouts
 * differ from the API client's (raw S3-like upload, no auth headers).
 */
final class PresignedImageUploadStrategy implements ImageUploadStrategy
{
    public function __construct(
        private readonly QameraApiClient $apiClient,
        private readonly ClientInterface $httpClient,
    ) {
    }

    public function uploadImage(
        string $localPath,
        string $filename,
        string $contentType,
        int $sizeBytes
    ): string {
        $presigned = $this->apiClient->requestUpload($filename, $contentType, $sizeBytes);
        if ($this->isExpired($presigned)) {
            $presigned = $this->apiClient->requestUpload($filename, $contentType, $sizeBytes);
        }

        if ($presigned->uploadUrl === null) {
            throw new TransportException(
                'PresignedImageUploadStrategy: upstream returned no uploadUrl '
                . '(non-presigned mode response). Client only requests presigned mode.'
            );
        }

        $stream = @fopen($localPath, 'rb');
        if ($stream === false) {
            throw new TransportException(sprintf(
                'PresignedImageUploadStrategy: cannot open local image file "%s" for reading.',
                $localPath
            ));
        }

        try {
            try {
                $this->httpClient->request('PUT', $presigned->uploadUrl, [
                    'body' => $stream,
                    'headers' => ['Content-Type' => $contentType],
                ]);
            } catch (ConnectException $e) {
                throw new TransportException(
                    'PresignedImageUploadStrategy: PUT failed: ' . $e->getMessage(),
                    $e
                );
            } catch (GuzzleException $e) {
                throw new TransportException(
                    'PresignedImageUploadStrategy: PUT failed: ' . $e->getMessage(),
                    $e
                );
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $presigned->assetId;
    }

    private function isExpired(PresignedUploadResponse $response): bool
    {
        if ($response->expiresAt === null) {
            // Non-presigned mode never sets expiresAt; treat as non-expiring.
            // The PUT below will fail fast on the null uploadUrl guard.
            return false;
        }
        try {
            $expires = new DateTimeImmutable($response->expiresAt);
        } catch (Exception $e) {
            // Unparseable timestamps are treated as expired — the upstream
            // contract requires a parseable ISO-8601 string, so this is a
            // defensive fallback only.
            return true;
        }
        return $expires <= new DateTimeImmutable('now');
    }
}
