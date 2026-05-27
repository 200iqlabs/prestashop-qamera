<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Dto;

/**
 * Embedded image inside a `ProductDetailResponse`. Matches upstream
 * `ProductImageDtoSchema` (schemas.ts ~ line 724).
 */
final class ProductImageDto
{
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
        public readonly string $createdAt,
    ) {
    }
}
