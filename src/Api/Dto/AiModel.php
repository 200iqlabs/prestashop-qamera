<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Dto;

final class AiModel
{
    /**
     * @param array<int, string> $supportedAspectRatios
     */
    public function __construct(
        public readonly string $id,
        public readonly string $provider,
        public readonly string $model,
        public readonly string $outputType,
        public readonly array $supportedAspectRatios,
        public readonly int $baseCreditCost,
    ) {
    }
}
