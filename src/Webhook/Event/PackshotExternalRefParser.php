<?php

declare(strict_types=1);

namespace QameraAi\Module\Webhook\Event;

/**
 * Strict parser for the `ps:<shopId>:<productId>:packshot:<uuid>` form.
 * Used by the pre-submit-race recovery path in `PackshotJobUpdater` to
 * recover `(id_shop, id_product)` when the webhook delivery arrives
 * before the submitter persists the local row.
 *
 * Anchored regex; rejects leading zeros, signs, whitespace, missing or
 * extra segments, and non-UUID-shaped trailing values. UUID match accepts
 * lowercase hex with the canonical 8-4-4-4-12 dash layout.
 */
final class PackshotExternalRefParser
{
    private const PATTERN =
        '/^ps:([1-9][0-9]*):([1-9][0-9]*):packshot:'
        . '([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/';

    /**
     * @throws InvalidExternalRefException
     */
    public static function parse(string $ref): PackshotExternalRef
    {
        if (preg_match(self::PATTERN, $ref, $m) !== 1) {
            throw new InvalidExternalRefException('invalid packshot external_ref shape');
        }

        return new PackshotExternalRef((int) $m[1], (int) $m[2], $m[3]);
    }
}
