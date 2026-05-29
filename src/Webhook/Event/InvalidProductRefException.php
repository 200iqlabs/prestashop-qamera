<?php

declare(strict_types=1);

namespace QameraAi\Module\Webhook\Event;

use RuntimeException;

/**
 * Thrown by {@see ProductRefParser} when a `job.product_ref` does not
 * match the canonical `ps:<shopId>:<productId>` shape.
 */
final class InvalidProductRefException extends RuntimeException
{
}
