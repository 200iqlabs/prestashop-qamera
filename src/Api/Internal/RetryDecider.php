<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Internal;

use GuzzleHttp\Exception\ConnectException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Encapsulates the retry policy from spec Requirement "Transient failures
 * are retried with exponential backoff up to four attempts":
 *
 * - retryable: ConnectException, HTTP 502/503/504, HTTP 429
 * - cap: 4 total attempts (one initial + three retries)
 * - delay: `250 ms × 2^N` between attempt N and N+1, OR `Retry-After`
 *   value clamped at 60 seconds when present
 */
class RetryDecider
{
    private const MAX_ATTEMPTS = 4;
    private const BASE_DELAY_MS = 250;
    private const MAX_RETRY_AFTER_SECONDS = 60;

    /**
     * @param int $retries 0 on the first attempt, 1 after one failed attempt, etc.
     */
    public function shouldRetry(
        int $retries,
        RequestInterface $request,
        ?ResponseInterface $response,
        ?Throwable $exception,
    ): bool {
        // attempts already made = $retries + 1; cap at MAX_ATTEMPTS.
        if ($retries + 1 >= self::MAX_ATTEMPTS) {
            return false;
        }

        if ($exception instanceof ConnectException) {
            return true;
        }

        if ($response === null) {
            return false;
        }

        $status = $response->getStatusCode();

        return in_array($status, [429, 502, 503, 504], true);
    }

    public function delayMs(int $retries, ?ResponseInterface $response): int
    {
        if ($response !== null && $response->hasHeader('Retry-After')) {
            $retryAfter = (int) $response->getHeaderLine('Retry-After');
            if ($retryAfter > 0) {
                return min($retryAfter, self::MAX_RETRY_AFTER_SECONDS) * 1000;
            }
        }

        return self::BASE_DELAY_MS * (2 ** $retries);
    }
}
