<?php

declare(strict_types=1);

namespace QameraAi\Module\Webhook\Event;

/**
 * Immutable `(shopId, productId)` pair parsed from a webhook payload's
 * `job.product_ref` (`ps:<shopId>:<productId>`). Distinct from the
 * registration-time external_ref which also carried an image segment.
 */
final class ProductRef
{
    public function __construct(
        public readonly int $shopId,
        public readonly int $productId
    ) {
    }
}
