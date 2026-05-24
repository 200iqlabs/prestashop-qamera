<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Dto;

final class ImageResponse
{
    public function __construct(
        public readonly string $id,
        public readonly string $productRef,
        public readonly string $sourceUrl,
        public readonly string $status,
    ) {
    }
}
