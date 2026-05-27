<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Support;

use QameraAi\Module\Webhook\Clock;

final class FakeClock implements Clock
{
    public function __construct(private int $now)
    {
    }

    public function nowEpoch(): int
    {
        return $this->now;
    }

    public function set(int $now): void
    {
        $this->now = $now;
    }
}
