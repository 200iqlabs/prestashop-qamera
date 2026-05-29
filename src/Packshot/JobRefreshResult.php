<?php

declare(strict_types=1);

namespace QameraAi\Module\Packshot;

/**
 * Outcome of one {@see JobsStatusRefresher::refresh} call. Carries the
 * reconciled (or cached, on a TTL hit / upstream failure) job state. When
 * `refreshError` is non-null the upstream pull failed and the status/url
 * fields are the last-known-good values, NOT freshly reconciled.
 */
final class JobRefreshResult
{
    public function __construct(
        public readonly string $status,
        public readonly ?string $outputUrl,
        public readonly ?string $outputUrlExpiresAt,
        public readonly ?string $lastErrorMessage,
        public readonly ?string $lastSyncedAt,
        public readonly ?string $refreshError = null,
    ) {
    }
}
