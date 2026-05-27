<?php

declare(strict_types=1);

namespace QameraAi\Module\Webhook\Log;

use PrestaShopLogger;
use QameraAi\Module\Webhook\WebhookLogger;

/**
 * Maps {@see WebhookLogger} levels onto PrestaShop's numeric severities
 * (1=info, 2=warning, 3=error) and the `QameraAiModule` log channel.
 */
final class PrestaShopLoggerAdapter implements WebhookLogger
{
    private const CHANNEL = 'QameraAiModule';

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

        PrestaShopLogger::addLog(
            $line,
            $severity,
            null,
            self::CHANNEL,
            null,
            true
        );
    }
}
