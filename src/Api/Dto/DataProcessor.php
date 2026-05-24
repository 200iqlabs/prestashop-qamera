<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Dto;

final class DataProcessor
{
    public function __construct(
        public readonly string $name,
        public readonly string $purpose,
    ) {
    }
}
