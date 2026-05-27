<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Dto;

final class AspectRatio
{
    public function __construct(
        public readonly string $value,
        public readonly string $label,
        public readonly bool $default,
    ) {
    }
}
