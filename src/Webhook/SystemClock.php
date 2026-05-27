<?php

declare(strict_types=1);

namespace QameraAi\Module\Webhook;

final class SystemClock implements Clock
{
    public function nowEpoch(): int
    {
        return time();
    }
}
