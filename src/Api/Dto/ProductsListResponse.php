<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Dto;

use QameraAi\Module\Api\Internal\ArrayOf;

final class ProductsListResponse
{
    /**
     * @param ProductListItem[] $items
     */
    public function __construct(
        #[ArrayOf(ProductListItem::class)]
        public readonly array $items,
        public readonly ?string $nextCursor = null,
    ) {
    }
}
