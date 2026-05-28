<?php

declare(strict_types=1);

namespace QameraAi\Module\Sync;

/**
 * Result of an {@see AnalysisStatusRefresher::refresh()} call. Returned
 * to BO controllers so they can render the up-to-date badge + Generate
 * gate without re-reading the row from the DB.
 *
 * When `refreshError` is non-null, the refresher fell back to the
 * cached row values because the upstream call failed; the other fields
 * carry whatever was previously persisted (possibly stale by definition).
 */
final class RefreshResult
{
    public function __construct(
        public readonly ?string $analysisStatus,
        public readonly int $describedCount,
        public readonly int $totalCount,
        public readonly ?string $refreshedAt,
        public readonly ?string $refreshError = null,
    ) {
    }
}
