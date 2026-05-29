<?php

declare(strict_types=1);

namespace QameraAi\Module\Packshot;

use QameraAi\Module\Api\Exception\ApiException;

/**
 * Aggregate outcome of one `PackshotJobSubmitter::submit()` invocation.
 * The submitter chunks bulk requests >100 subjects into multiple sessions
 * (see {@see PackshotJobSubmitter}); the controller renders a single
 * flash message summarising both successful and failed chunks.
 *
 * `chunkFailures` carries the (1-based chunk index → reason) map so the
 * BO can surface "session 2 of 3 failed: <reason>" without re-running.
 *
 * `firstApiError` is the first upstream {@see ApiException} the submitter
 * swallowed into a chunk failure (null if all chunks succeeded or the only
 * failures were local DB errors). The controller uses it to classify a
 * photo-shoot 422 (`packshot_not_approved` / gate-disabled) into a friendly
 * flash instead of the raw upstream string — the submitter never re-throws,
 * so this is the only channel for the envelope/code.
 */
final class SubmitResult
{
    /**
     * @param string[]      $orderIds        order_ids returned by successful chunks
     * @param array<int, string> $chunkFailures   keyed by 1-based chunk index
     */
    public function __construct(
        public readonly int $sessionsSubmitted,
        public readonly int $sessionsFailed,
        public readonly int $jobsPersisted,
        public readonly array $orderIds,
        public readonly array $chunkFailures,
        public readonly ?ApiException $firstApiError = null,
    ) {
    }

    public function isFullSuccess(): bool
    {
        return $this->sessionsFailed === 0;
    }

    public function isFullFailure(): bool
    {
        return $this->sessionsSubmitted === 0;
    }
}
