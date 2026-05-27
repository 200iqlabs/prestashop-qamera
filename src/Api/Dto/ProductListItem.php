<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Dto;

/**
 * Element of `ProductsListResponse.items`. `sourceMetadata` is upstream-
 * required (`z.record(...)`, no `.nullable()`) but may be an empty object.
 */
final class ProductListItem
{
    /**
     * @param array<string, mixed> $sourceMetadata
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $externalRef,
        public readonly string $displayName,
        public readonly ?string $sku,
        public readonly ?string $description,
        public readonly array $sourceMetadata,
        public readonly int $imageCount,
        public readonly int $packshotCount,
        public readonly ?string $deletedAt,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {
    }
}
