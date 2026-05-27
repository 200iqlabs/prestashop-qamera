<?php

declare(strict_types=1);

namespace QameraAi\Module\Webhook;

/**
 * @psalm-immutable
 */
final class ParsedSignature
{
    /**
     * @param list<string> $signatures
     */
    public function __construct(
        public readonly int $timestamp,
        public readonly array $signatures
    ) {
    }
}
