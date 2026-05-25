<?php

declare(strict_types=1);

namespace QameraAi\Module\Sync;

use PrestaShopLogger;

/**
 * Thin instance wrapper around PrestaShop's static `PrestaShopLogger::addLog`.
 * The wrapper exists so that classes which need to log can take a
 * constructor dependency (mockable in unit tests) instead of calling
 * the static directly.
 */
class PrestaShopLoggerWrapper
{
    public function addLog(
        string $message,
        int $severity = 1,
        ?int $errorCode = null,
        ?string $objectType = null,
        ?int $objectId = null,
        bool $allowDuplicate = false
    ): void {
        PrestaShopLogger::addLog(
            $message,
            $severity,
            $errorCode,
            $objectType,
            $objectId,
            $allowDuplicate
        );
    }
}
