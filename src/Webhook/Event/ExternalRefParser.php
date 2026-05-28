<?php

declare(strict_types=1);

namespace QameraAi\Module\Webhook\Event;

/**
 * Strict parser for the canonical `ps:<shopId>:<productId>:image:<imageId>`
 * external_ref shape used by `product-image-sync` when calling the
 * upstream `registerImage` endpoint.
 *
 * Refusal modes:
 *   - non-`ps:` prefix → reject (forward-compat with future prefixes is
 *     deliberately NOT supported: the dispatcher logs WARNING and skips)
 *   - any segment not a strict positive integer
 *   - leading zeros, signs, whitespace inside or around the ref
 *   - truncated / extra segments
 *
 * Pure, stateless, no I/O.
 */
final class ExternalRefParser
{
    /**
     * @throws InvalidExternalRefException
     */
    public static function parse(string $ref): ExternalRef
    {
        // Anchors + strict positive-integer pattern (no leading zeros, no
        // signs). The `image` literal between the productId and imageId
        // pins the canonical shape — anything else (e.g. "packshot",
        // "thumb") is rejected here, NOT silently accepted.
        if (preg_match('/^ps:([1-9][0-9]*):([1-9][0-9]*):image:([1-9][0-9]*)$/', $ref, $m) !== 1) {
            throw new InvalidExternalRefException('invalid external_ref shape');
        }

        return new ExternalRef(
            (int) $m[1],
            (int) $m[2],
            (int) $m[3]
        );
    }
}
