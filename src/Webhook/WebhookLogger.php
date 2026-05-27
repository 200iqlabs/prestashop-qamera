<?php

declare(strict_types=1);

namespace QameraAi\Module\Webhook;

/**
 * Tiny framework-free logger surface so the handler can be unit-tested
 * with an in-memory spy. The production adapter wraps PrestaShopLogger.
 *
 * Levels mirror the spec's "Operator-visible logging" requirement:
 * info on accept, warning on duplicate, error on rejection / repo failure.
 */
interface WebhookLogger
{
    /**
     * @param array<string, string|int|null> $context
     */
    public function info(string $message, array $context = []): void;

    /**
     * @param array<string, string|int|null> $context
     */
    public function warning(string $message, array $context = []): void;

    /**
     * @param array<string, string|int|null> $context
     */
    public function error(string $message, array $context = []): void;
}
