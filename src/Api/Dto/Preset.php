<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Dto;

final class Preset
{
    /**
     * @param array<string, string> $descriptionI18n
     * @param array<int, string>    $gallery
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $slug,
        public readonly string $name,
        public readonly array $descriptionI18n,
        public readonly int $creditCost,
        public readonly ?string $outputType,
        public readonly bool $isFree,
        public readonly ?string $coverUrl,
        public readonly string $quantityGuidelines,
        public readonly string $qualityGuidelines,
        public readonly array $gallery,
    ) {
    }
}
