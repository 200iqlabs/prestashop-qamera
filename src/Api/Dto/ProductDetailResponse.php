<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Dto;

use QameraAi\Module\Api\Internal\ArrayOf;

/**
 * `GET /products/{idOrRef}` response. Replaces Phase-1 `ProductResponse`
 * which only carried `id, ref, title, status` (all wrong against upstream).
 */
final class ProductDetailResponse
{
    /**
     * @param array<string, mixed>     $sourceMetadata
     * @param ProductImageDto[]        $images
     * @param ProductPackshotDto[]     $packshots
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $externalRef,
        public readonly string $displayName,
        public readonly ?string $sku,
        public readonly ?string $description,
        public readonly array $sourceMetadata,
        public readonly ?string $deletedAt,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        #[ArrayOf(ProductImageDto::class)]
        public readonly array $images,
        public readonly bool $imagesTruncated,
        #[ArrayOf(ProductPackshotDto::class)]
        public readonly array $packshots,
        public readonly bool $packshotsTruncated,
    ) {
    }
}
