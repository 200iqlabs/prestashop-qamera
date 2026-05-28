<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Dto;

/**
 * Mannequin model surfaced under `GET /models`. Shape mirrors {@see Scenery}
 * because upstream serves both endpoints from the same `cg_models` table
 * with different `kind` filters (mannequin vs. scenery). `source` is
 * `'account'` | `'marketplace'`.
 */
final class MannequinModel
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $thumbnail,
        public readonly string $source,
        public readonly string $status,
        public readonly string $createdAt,
    ) {
    }
}
