<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Dto;

final class JobResponse
{
    /**
     * @param string[] $resultUrls
     */
    public function __construct(
        public readonly string $id,
        public readonly string $productRef,
        public readonly string $status,
        public readonly array $resultUrls = [],
    ) {
    }
}
