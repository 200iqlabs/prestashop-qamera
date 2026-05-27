<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Dto;

/**
 * `thumbnail`, `voting`, `status` are nullable per upstream zod.
 * `source` is `'account'` | `'marketplace'`.
 */
final class Scenery
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $thumbnail,
        public readonly ?string $voting,
        public readonly ?string $status,
        public readonly string $source,
        public readonly string $createdAt,
    ) {
    }
}
