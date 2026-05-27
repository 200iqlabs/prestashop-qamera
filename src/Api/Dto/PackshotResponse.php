<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Dto;

final class PackshotResponse
{
    public function __construct(
        public readonly string $externalRef,
        public readonly string $productId,
        public readonly string $packshotId,
        public readonly string $status,
    ) {
    }
}
