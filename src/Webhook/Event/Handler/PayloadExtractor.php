<?php

declare(strict_types=1);

namespace QameraAi\Module\Webhook\Event\Handler;

/**
 * Strict-typed payload field extractor shared by the four `job.*`
 * handlers. `string()` returns null if the field is absent, not a
 * string, or an empty string — callers treat null as "missing" and log
 * at ERROR. `nullableString()` returns null only on absent / non-string
 * (an empty string would still surface as ''), used for fields the
 * upstream may legitimately omit (e.g. `job_id`, `error_message`).
 */
final class PayloadExtractor
{
    /**
     * @param array<string, mixed> $payload
     */
    public static function string(array $payload, string $field): ?string
    {
        if (!isset($payload[$field]) || !is_string($payload[$field]) || $payload[$field] === '') {
            return null;
        }
        return $payload[$field];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function nullableString(array $payload, string $field): ?string
    {
        if (!isset($payload[$field]) || !is_string($payload[$field])) {
            return null;
        }
        return $payload[$field];
    }

    /**
     * Extract a URL field and reject anything that is not an http(s) URL.
     * `javascript:`, `data:`, `vbscript:`, mailto:, etc. all return null —
     * the BO renders this value directly into href/src so an unfiltered
     * scheme is an XSS vector when the operator clicks the resulting link.
     *
     * @param array<string, mixed> $payload
     */
    public static function nullableHttpUrl(array $payload, string $field): ?string
    {
        $raw = self::nullableString($payload, $field);
        if ($raw === null || $raw === '') {
            return null;
        }
        // filter_var enforces a valid URL shape; we additionally restrict
        // the scheme to http/https since FILTER_VALIDATE_URL accepts
        // arbitrary schemes (including javascript: in some PHP builds).
        if (filter_var($raw, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $scheme = parse_url($raw, PHP_URL_SCHEME);
        if (!is_string($scheme) || ($scheme !== 'http' && $scheme !== 'https')) {
            return null;
        }
        return $raw;
    }
}
