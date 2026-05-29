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

    /**
     * Read a non-empty string field from the nested `job` object of the real
     * webhook wire body (`{ event, job:{…}, outputs:[…] }`). Returns null if
     * `job` is absent/not-an-object or the field is missing/empty/non-string.
     *
     * @param array<string, mixed> $payload
     */
    public static function jobString(array $payload, string $field): ?string
    {
        $job = $payload['job'] ?? null;
        if (!is_array($job)) {
            return null;
        }
        return self::string($job, $field);
    }

    /**
     * The presigned URL of the first output, http(s)-validated (the BO renders
     * it into img/href). Null when there are no outputs or the first output's
     * URL is absent / a non-http scheme.
     *
     * @param array<string, mixed> $payload
     */
    public static function firstOutputUrl(array $payload): ?string
    {
        $outputs = $payload['outputs'] ?? null;
        if (!is_array($outputs) || !isset($outputs[0]) || !is_array($outputs[0])) {
            return null;
        }
        return self::nullableHttpUrl($outputs[0], 'url');
    }

    /**
     * Extract a human message from the nested `job.error` object
     * (`{ code, message_i18n, retryable }`): prefer the requested locale, then
     * `en`, then any available message, finally the error `code`. Null when
     * there is no error object.
     *
     * @param array<string, mixed> $payload
     */
    public static function jobErrorMessage(array $payload, string $locale): ?string
    {
        $job = $payload['job'] ?? null;
        if (!is_array($job)) {
            return null;
        }
        $error = $job['error'] ?? null;
        // Live wire shape (2026-05-29 smoke): job.error is frequently a plain
        // string (e.g. "PLUGIN_JOB_MISSING_CATALOG_ENTRY: …"), not the
        // documented {code, message_i18n, retryable} object. Return it verbatim
        // so last_error_message is populated; the object handling below stays
        // for the REST-DTO shape and any future server alignment.
        if (is_string($error)) {
            return $error !== '' ? $error : null;
        }
        if (!is_array($error)) {
            return null;
        }

        $messages = $error['message_i18n'] ?? null;
        if (is_array($messages)) {
            foreach ([$locale, 'en'] as $preferred) {
                if (isset($messages[$preferred]) && is_string($messages[$preferred]) && $messages[$preferred] !== '') {
                    return $messages[$preferred];
                }
            }
            foreach ($messages as $message) {
                if (is_string($message) && $message !== '') {
                    return $message;
                }
            }
        }

        // Some providers shape `job.error` as `{code, message}` (no i18n
        // map). Prefer a non-empty plain message before the bare code.
        $plain = $error['message'] ?? null;
        if (is_string($plain) && $plain !== '') {
            return $plain;
        }

        $code = $error['code'] ?? null;
        return is_string($code) && $code !== '' ? $code : null;
    }
}
