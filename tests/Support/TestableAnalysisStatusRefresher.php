<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Support;

use QameraAi\Module\Sync\AnalysisStatusRefresher;

/**
 * Test-only subclass that freezes `now()` so the TTL gate is
 * deterministic and the persisted timestamp string is predictable.
 * Used by {@see \QameraAi\Module\Tests\Unit\Sync\AnalysisStatusRefresherTest}.
 */
final class TestableAnalysisStatusRefresher extends AnalysisStatusRefresher
{
    protected function now(): string
    {
        return date('Y-m-d H:i:s', $this->nowTimestamp());
    }

    protected function nowTimestamp(): int
    {
        return 1779000000;
    }
}
