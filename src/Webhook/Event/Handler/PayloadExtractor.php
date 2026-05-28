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
}
