<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Dto;

use QameraAi\Module\Api\Exception\ValidationException;

/**
 * Embedded image inside a `ProductDetailResponse`. Matches upstream
 * `ProductImageDtoSchema` (schemas.ts ~ line 803 post-PR-204).
 */
final class ProductImageDto
{
    public const ANALYSIS_STATUS_PENDING = 'pending';
    public const ANALYSIS_STATUS_PROCESSING = 'processing';
    public const ANALYSIS_STATUS_DESCRIBED = 'described';
    public const ANALYSIS_STATUS_ERROR = 'error';

    public const ANALYSIS_STATUSES = [
        self::ANALYSIS_STATUS_PENDING,
        self::ANALYSIS_STATUS_PROCESSING,
        self::ANALYSIS_STATUS_DESCRIBED,
        self::ANALYSIS_STATUS_ERROR,
    ];

    public function __construct(
        public readonly string $id,
        public readonly ?string $externalRef,
        public readonly string $productId,
        public readonly string $assetId,
        public readonly int $byteSize,
        public readonly string $contentType,
        public readonly ?int $width,
        public readonly ?int $height,
        public readonly string $sha256,
        public readonly string $analysisStatus,
        public readonly ?string $analyzedAt,
        public readonly string $createdAt,
    ) {
        if (!in_array($analysisStatus, self::ANALYSIS_STATUSES, true)) {
            throw ValidationException::invalidEnumValue(
                'analysis_status',
                $analysisStatus,
                self::ANALYSIS_STATUSES,
            );
        }
    }
}
