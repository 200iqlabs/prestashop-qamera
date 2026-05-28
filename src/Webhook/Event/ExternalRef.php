<?php

declare(strict_types=1);

namespace QameraAi\Module\Webhook\Event;

/**
 * Parsed `external_ref` payload value, in canonical
 * `ps:<shopId>:<productId>:image:<imageId>` shape. All three components
 * are positive integers (>= 1) — the parser rejects zero, negatives,
 * leading-zero notations and signed forms.
 */
final class ExternalRef
{
    public function __construct(
        public readonly int $shopId,
        public readonly int $productId,
        public readonly int $imageId
    ) {
    }
}
