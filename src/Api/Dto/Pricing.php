<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Dto;

final class Pricing
{
    public function __construct(
        public readonly int $creditsPerImage,
        public readonly int $creditsPerPackshot,
        public readonly ?int $monthlyQuota = null,
    ) {
    }
}
