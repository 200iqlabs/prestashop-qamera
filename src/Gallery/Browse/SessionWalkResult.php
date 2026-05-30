<?php

declare(strict_types=1);

namespace QameraAi\Module\Gallery\Browse;

/**
 * Outcome of the lazy photo-shoot jobs walk: the session images found
 * (each already attributed to a product image) and whether the bounded
 * jobs cap was reached before the walk exhausted all jobs — in which case
 * the UI surfaces a "showing recent sessions" notice (D6).
 */
final class SessionWalkResult
{
    /**
     * @param BrowseSessionImage[] $sessions
     */
    public function __construct(
        public readonly array $sessions,
        public readonly bool $capHit,
    ) {
    }
}
