<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Dto;

use QameraAi\Module\Api\Internal\ArrayOf;

final class ProductsListResponse
{
    /**
     * @param ProductResponse[] $items
     */
    public function __construct(
        #[ArrayOf(ProductResponse::class)]
        public readonly array $items,
        public readonly ?string $nextCursor = null,
    ) {
    }
}
