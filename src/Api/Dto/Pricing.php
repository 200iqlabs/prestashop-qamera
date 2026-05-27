<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Dto;

use QameraAi\Module\Api\Internal\ArrayOf;

final class Pricing
{
    /**
     * @param PricingEntry[] $pricing
     */
    public function __construct(
        #[ArrayOf(PricingEntry::class)]
        public readonly array $pricing,
        public readonly string $currency,
    ) {
    }

    /**
     * @return PricingEntry[]
     */
    public function getEntries(): array
    {
        return $this->pricing;
    }
}
