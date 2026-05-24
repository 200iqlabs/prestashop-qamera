<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Exception;

use Throwable;

/**
 * HTTP 429 after retry budget exhausted. `$retryAfter` is the seconds value
 * from the server's `Retry-After` header, clamped to a non-negative integer.
 */
final class RateLimitException extends ApiException
{
    public function __construct(
        string $message,
        private readonly int $retryAfter,
        ?ErrorEnvelope $envelope = null,
        ?string $correlationId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 429, $envelope, $correlationId, $previous);
    }

    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }
}
