<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Exception;

use Throwable;

/**
 * Network-level failure with no HTTP response (DNS, TLS, connection reset).
 * Carries the underlying Guzzle exception as `$previous`; never carries an
 * envelope or status code.
 */
final class TransportException extends ApiException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, null, null, null, $previous);
    }
}
