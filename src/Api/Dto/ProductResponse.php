<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Dto;

final class ProductResponse
{
    public function __construct(
        public readonly string $id,
        public readonly string $ref,
        public readonly string $title,
        public readonly string $status,
    ) {
    }
}
