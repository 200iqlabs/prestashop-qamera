<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Dto;

final class PricingEntry
{
    public function __construct(
        public readonly string $jobType,
        public readonly string $provider,
        public readonly string $model,
        public readonly int $creditCost,
    ) {
    }
}
