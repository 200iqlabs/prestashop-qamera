<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Internal;

/**
 * Builds the static header set the client attaches to every outbound
 * request. Values are resolved once at construction (DI factory wires PS
 * context); the builder itself has no runtime dependencies, so tests can
 * pass any string literal.
 */
final class HeaderBuilder
{
    /**
     * @param non-empty-string $apiKey
     * @param non-empty-string $userAgent     e.g. `QameraAi-PrestaShop-Module/1.0.0 (9.0.0)`
     * @param non-empty-string $acceptLanguage iso code; caller falls back to `en` upstream
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $userAgent,
        private readonly string $acceptLanguage,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function baseHeaders(): array
    {
        return [
            'X-Api-Key' => $this->apiKey,
            'User-Agent' => $this->userAgent,
            'Accept-Language' => $this->acceptLanguage,
            'Accept' => 'application/json',
        ];
    }
}
