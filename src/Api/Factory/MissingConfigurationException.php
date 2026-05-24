<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Factory;

use RuntimeException;

/**
 * Thrown by {@see QameraApiClientFactory::create()} when required PS
 * Configuration values (currently: API key) are missing. Distinct from
 * {@see \QameraAi\Module\Api\Exception\ApiException} because no HTTP call
 * ever left the box — callers (Test Connection controller) surface this as
 * a "configure your credentials first" message, not a transport / auth
 * failure.
 */
final class MissingConfigurationException extends RuntimeException
{
}
