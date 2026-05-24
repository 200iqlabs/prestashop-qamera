<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Exception;

/**
 * Parsed shape of the Qamera AI Plugin API error envelope:
 * `{ error: { code, message_i18n, retryable, doc_url } }`.
 *
 * Carried by every {@see ApiException} subclass that received an HTTP
 * response. `TransportException` (connection-level) has no envelope.
 */
final class ErrorEnvelope
{
    /**
     * @param array<string, string> $messageI18n locale code (`en`, `pl`, `uk`, ...) => human message
     */
    public function __construct(
        public readonly string $code,
        public readonly array $messageI18n,
        public readonly bool $retryable,
        public readonly ?string $docUrl,
    ) {
    }

    public function messageFor(string $locale, string $fallbackLocale = 'en'): string
    {
        if (isset($this->messageI18n[$locale])) {
            return $this->messageI18n[$locale];
        }
        if (isset($this->messageI18n[$fallbackLocale])) {
            return $this->messageI18n[$fallbackLocale];
        }

        // Last resort: return the first available message, or the code if none.
        // Copy to a local first — `reset()` mutates the array's internal
        // pointer, which is illegal on a readonly property in PHP 8.1+.
        $messages = $this->messageI18n;
        $first = reset($messages);

        return is_string($first) ? $first : $this->code;
    }
}
