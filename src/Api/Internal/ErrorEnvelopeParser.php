<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Internal;

use Psr\Http\Message\ResponseInterface;
use QameraAi\Module\Api\Exception\ErrorEnvelope;

/**
 * Parses the standard plugin error envelope
 * `{ error: { code, message_i18n, retryable, doc_url } }` out of a
 * non-2xx response body. Tolerates non-JSON bodies and missing fields —
 * returns `null` so the caller can still raise a typed exception with no
 * envelope.
 */
final class ErrorEnvelopeParser
{
    public function parse(ResponseInterface $response): ?ErrorEnvelope
    {
        $body = (string) $response->getBody();
        if ($body === '') {
            return null;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($decoded) || !isset($decoded['error']) || !is_array($decoded['error'])) {
            return null;
        }

        $error = $decoded['error'];
        $code = $error['code'] ?? null;
        if (!is_string($code) || $code === '') {
            return null;
        }

        $messageI18n = [];
        if (isset($error['message_i18n']) && is_array($error['message_i18n'])) {
            foreach ($error['message_i18n'] as $locale => $message) {
                if (is_string($locale) && is_string($message)) {
                    $messageI18n[$locale] = $message;
                }
            }
        }

        $retryable = isset($error['retryable']) && $error['retryable'] === true;
        $docUrl = isset($error['doc_url']) && is_string($error['doc_url']) ? $error['doc_url'] : null;

        return new ErrorEnvelope($code, $messageI18n, $retryable, $docUrl);
    }
}
