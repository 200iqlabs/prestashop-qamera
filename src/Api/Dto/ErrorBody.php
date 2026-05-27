<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Dto;

/**
 * Upstream `ErrorBodySchema` shape — appears inline inside `JobDto.error`
 * and on the wire as part of API error envelopes. Distinct from
 * {@see \QameraAi\Module\Api\Exception\ErrorEnvelope} which wraps the
 * top-level `{error: {…}}` HTTP error response.
 */
final class ErrorBody
{
    /**
     * @param array<string, string> $messageI18n
     */
    public function __construct(
        public readonly string $code,
        public readonly array $messageI18n,
        public readonly bool $retryable,
        public readonly ?string $docUrl = null,
    ) {
    }
}
