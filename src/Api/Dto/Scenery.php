<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Dto;

final class Scenery
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $previewUrl = null,
    ) {
    }
}
