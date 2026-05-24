<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Dto;

use QameraAi\Module\Api\Internal\ArrayOf;

final class MeResponse
{
    /**
     * @param DataProcessor[] $dataProcessors
     */
    public function __construct(
        public readonly string $accountId,
        public readonly string $accountName,
        public readonly string $accountSlug,
        public readonly int $creditsBalance,
        public readonly string $subscriptionPlan,
        public readonly int $rateLimitPerMin,
        public readonly InstallationInfo $installation,
        #[ArrayOf(DataProcessor::class)]
        public readonly array $dataProcessors,
    ) {
    }
}
