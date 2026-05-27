<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Dto;

/**
 * Response from `POST /assets/upload`.
 *
 * Upload fields (`uploadUrl`, `uploadToken`, `expiresAt`) are nullable
 * because upstream returns null for them in multipart-mode responses.
 * This client only sends `mode=presigned`, but the DTO honestly mirrors
 * the server's contract.
 */
final class PresignedUploadResponse
{
    public function __construct(
        public readonly string $assetId,
        public readonly string $bucket,
        public readonly string $storagePath,
        public readonly ?string $uploadUrl,
        public readonly ?string $uploadToken,
        public readonly ?string $expiresAt,
    ) {
    }
}
