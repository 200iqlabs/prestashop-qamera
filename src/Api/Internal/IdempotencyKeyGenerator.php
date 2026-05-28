<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Internal;

use Ramsey\Uuid\Uuid;

/**
 * Thin wrapper around Ramsey UUID so tests can substitute a deterministic
 * generator and assert idempotency-key stability across retries.
 *
 * Prefers uuid7 (time-ordered, better for upstream log grouping) but
 * falls back to uuid4 when another PrestaShop module ships an older
 * ramsey/uuid (<4.7) whose autoloader wins over ours.
 */
class IdempotencyKeyGenerator
{
    public function generate(): string
    {
        if ($this->hasUuid7()) {
            return Uuid::uuid7()->toString();
        }
        return Uuid::uuid4()->toString();
    }

    /**
     * Indirection over `method_exists` so the integration suite can
     * subclass and force the uuid4 fallback path — otherwise that
     * branch is invisible to tests in any environment that ships
     * ramsey/uuid 4.7+ (where uuid7 always exists).
     */
    protected function hasUuid7(): bool
    {
        return method_exists(Uuid::class, 'uuid7');
    }
}
