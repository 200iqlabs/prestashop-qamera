<?php

declare(strict_types=1);

namespace QameraAi\Module\Webhook\Event;

/**
 * Parsed `external_ref` in `ps:<shopId>:<productId>:packshot:<uuid>`
 * shape. Distinct from {@see ExternalRef} which parses the `:image:` form
 * used by `product-image-sync`. The packshot form carries a UUID v4 in
 * the trailing segment, generated client-side by the submitter for
 * `Subject.packshot_external_ref` and echoed back on `job.*` deliveries
 * when the upstream auto-registered packshot row carries it.
 */
final class PackshotExternalRef
{
    public function __construct(
        public readonly int $shopId,
        public readonly int $productId,
        public readonly string $packshotUuid
    ) {
    }
}
