<?php

declare(strict_types=1);

namespace QameraAi\Module\Webhook\Event;

/**
 * Strict parser for the `ps:<shopId>:<productId>` product reference carried
 * in the webhook payload's `job.product_ref` field (built by
 * `ProductRefBuilder`). This is the webhook-side counterpart to the
 * registration-time external_ref — but WITHOUT the trailing `:image:<id>`
 * segment, which the webhook contract never sends.
 *
 * Refusal modes (mirrors the strictness of the former external_ref parser):
 *   - non-`ps:` prefix
 *   - any segment not a strict positive integer (no leading zeros, no signs)
 *   - leading/trailing whitespace
 *   - truncated or extra segments (incl. the registration `:image:<id>` shape)
 *
 * Pure, stateless, no I/O.
 */
final class ProductRefParser
{
    /**
     * @throws InvalidProductRefException
     */
    public static function parse(string $ref): ProductRef
    {
        if (preg_match('/^ps:([1-9][0-9]*):([1-9][0-9]*)$/', $ref, $m) !== 1) {
            throw new InvalidProductRefException('invalid product_ref shape');
        }

        return new ProductRef(
            (int) $m[1],
            (int) $m[2]
        );
    }
}
