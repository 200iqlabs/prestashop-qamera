<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Dto;

use QameraAi\Module\Api\Internal\ArrayOf;

final class JobsListResponse
{
    /**
     * @param JobResponse[] $items
     */
    public function __construct(
        #[ArrayOf(JobResponse::class)]
        public readonly array $items,
        public readonly ?string $nextCursor = null,
    ) {
    }
}
