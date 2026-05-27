<?php

declare(strict_types=1);

namespace QameraAi\Module\Webhook\Log;

use QameraAi\Module\Sync\PrestaShopLoggerWrapper;
use QameraAi\Module\Webhook\WebhookLogger;

/**
 * Maps {@see WebhookLogger} levels (info/warning/error) onto PrestaShop's
 * numeric severities (1/2/3) and routes them to the shared
 * `QameraAiModule` log channel via the existing {@see PrestaShopLoggerWrapper}.
 *
 * Composing the wrapper (rather than calling `PrestaShopLogger::addLog`
 * directly) keeps a single seam over PrestaShop's static logger — any
 * future change (PSR-3 swap, channel rename, context redaction) lands in
 * one place instead of two.
 */
final class PrestaShopLoggerAdapter implements WebhookLogger
{
    public const CHANNEL = 'QameraAiModule';

    public function __construct(private readonly PrestaShopLoggerWrapper $logger)
    {
    }

    public function info(string $message, array $context = []): void
    {
        $this->emit(1, $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->emit(2, $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->emit(3, $message, $context);
    }

    /**
     * @param array<string, string|int|null> $context
     */
    private function emit(int $severity, string $message, array $context): void
    {
        $line = '[QameraAi][webhook] ' . $message;
        if ($context !== []) {
            $pairs = [];
            foreach ($context as $key => $value) {
                $pairs[] = $key . '=' . ($value === null ? '-' : (string) $value);
            }
            $line .= ' ' . implode(' ', $pairs);
        }

        $this->logger->addLog($line, $severity, null, self::CHANNEL, null, true);
    }
}
