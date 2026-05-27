<?php

declare(strict_types=1);

namespace QameraAi\Module\Webhook;

/**
 * Constant-time HMAC-SHA256 verifier with multi-v1 tolerance.
 *
 * The signed string is `<timestamp>.<rawBody>` (literal dot, no
 * whitespace). The delivery is authentic if ANY `v1=` value in the
 * parsed header matches the locally-computed HMAC under hash_equals.
 */
final class HmacVerifier
{
    public function verify(string $rawBody, ParsedSignature $sig, string $secret): bool
    {
        $expected = hash_hmac('sha256', $sig->timestamp . '.' . $rawBody, $secret);

        $match = false;
        foreach ($sig->signatures as $candidate) {
            // Iterate every candidate (no early break on first match) so
            // wall-clock time depends only on the candidate count, not on
            // which position matched.
            if (hash_equals($expected, $candidate)) {
                $match = true;
            }
        }

        return $match;
    }
}
