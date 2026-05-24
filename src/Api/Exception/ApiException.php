<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Exception;

use RuntimeException;
use Throwable;

/**
 * Base of the Qamera API exception hierarchy.
 *
 * Subclasses correspond to actionable caller buckets (auth / not-found /
 * validation / rate-limit / server / transport). Callers catch the concrete
 * subclass; matching on status code is reserved for the rare audit-log path.
 */
abstract class ApiException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly ?int $statusCode = null,
        private readonly ?ErrorEnvelope $envelope = null,
        private readonly ?string $correlationId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    public function getEnvelope(): ?ErrorEnvelope
    {
        return $this->envelope;
    }

    public function getCorrelationId(): ?string
    {
        return $this->correlationId;
    }
}
