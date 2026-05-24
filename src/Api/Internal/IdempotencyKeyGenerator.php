<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Internal;

use Ramsey\Uuid\Uuid;

/**
 * Thin wrapper around `Uuid::uuid7()->toString()` so tests can substitute
 * a deterministic generator and assert idempotency-key stability across
 * retries.
 */
class IdempotencyKeyGenerator
{
    public function generate(): string
    {
        return Uuid::uuid7()->toString();
    }
}
