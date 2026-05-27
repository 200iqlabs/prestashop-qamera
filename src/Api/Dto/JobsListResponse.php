<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Dto;

use QameraAi\Module\Api\Internal\ArrayOf;

final class JobsListResponse
{
    /**
     * @param JobDto[] $jobs
     */
    public function __construct(
        #[ArrayOf(JobDto::class)]
        public readonly array $jobs,
        public readonly ?string $nextCursor = null,
    ) {
    }
}
