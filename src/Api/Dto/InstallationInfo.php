<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Dto;

final class InstallationInfo
{
    public function __construct(
        public readonly string $id,
        public readonly string $platform,
        public readonly string $status,
    ) {
    }
}
