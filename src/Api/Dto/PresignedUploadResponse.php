<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Dto;

final class PresignedUploadResponse
{
    public function __construct(
        public readonly string $uploadUrl,
        public readonly string $assetId,
        public readonly string $expiresAt,
    ) {
    }
}
