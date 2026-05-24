<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Dto;

final class AspectRatio
{
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $ratio,
    ) {
    }
}
