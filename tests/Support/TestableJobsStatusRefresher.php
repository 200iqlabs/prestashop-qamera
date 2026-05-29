<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Support;

use QameraAi\Module\Packshot\JobsStatusRefresher;

/**
 * Test-only subclass that freezes `now()` so the TTL gate is deterministic
 * and the persisted timestamp is predictable. Used by
 * {@see \QameraAi\Module\Tests\Unit\Packshot\JobsStatusRefresherTest}.
 */
final class TestableJobsStatusRefresher extends JobsStatusRefresher
{
    public const FROZEN_TS = 1779000000;

    protected function now(): string
    {
        return date('Y-m-d H:i:s', $this->nowTimestamp());
    }

    protected function nowTimestamp(): int
    {
        return self::FROZEN_TS;
    }
}
