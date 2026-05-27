<?php

declare(strict_types=1);

namespace QameraAi\Module\Webhook;

/**
 * Rejects deliveries outside the window [now-300s, now+60s]. Bounds
 * are inclusive on both sides per design D4 / spec scenarios.
 */
final class ReplayGuard
{
    public const MAX_PAST_SECONDS = 300;
    public const MAX_FUTURE_SECONDS = 60;

    public function __construct(private readonly Clock $clock)
    {
    }

    public function isFresh(int $signedTimestamp): bool
    {
        $now = $this->clock->nowEpoch();
        $delta = $now - $signedTimestamp;

        if ($delta > self::MAX_PAST_SECONDS) {
            return false;
        }
        if ($delta < -self::MAX_FUTURE_SECONDS) {
            return false;
        }

        return true;
    }
}
