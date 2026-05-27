<?php

declare(strict_types=1);

namespace QameraAi\Module\Webhook;

/**
 * Parses the `X-Qamera-Signature` header in the form
 * `t=<unix-seconds>,v1=<hex>[,v1=<hex>...]` into a structured value.
 *
 * Framework-free — no PrestaShop helpers, no Symfony, no globals.
 */
final class SignatureHeaderParser
{
    public function parse(string $header): ParsedSignature
    {
        $trimmed = trim($header);
        if ($trimmed === '') {
            throw new MalformedSignatureException('empty header');
        }

        $parts = explode(',', $trimmed);
        $timestamp = null;
        $signatures = [];

        foreach ($parts as $part) {
            if ($part === '' || $part !== trim($part)) {
                // Empty segment (trailing comma) or whitespace padding
                // around an entry — reject as malformed.
                throw new MalformedSignatureException('malformed entry');
            }

            $eq = strpos($part, '=');
            if ($eq === false) {
                throw new MalformedSignatureException('missing key=value');
            }

            $key = substr($part, 0, $eq);
            $value = substr($part, $eq + 1);

            if ($key !== trim($key) || $value !== trim($value)) {
                throw new MalformedSignatureException('whitespace inside entry');
            }

            if ($key === 't') {
                if ($timestamp !== null) {
                    throw new MalformedSignatureException('duplicate t');
                }
                if ($value === '' || !ctype_digit($value)) {
                    throw new MalformedSignatureException('non-numeric t');
                }
                $timestamp = (int) $value;
            } elseif ($key === 'v1') {
                if ($value === '') {
                    throw new MalformedSignatureException('empty v1');
                }
                $signatures[] = $value;
            }
            // Unknown keys are ignored for forward-compatibility.
        }

        if ($timestamp === null) {
            throw new MalformedSignatureException('missing t');
        }
        if ($signatures === []) {
            throw new MalformedSignatureException('missing v1');
        }

        return new ParsedSignature($timestamp, array_values($signatures));
    }
}
