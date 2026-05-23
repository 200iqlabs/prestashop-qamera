<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Placeholder so CI has at least one passing test from day 1. Replaced
 * by real suites in Phase 2 (QameraApiClient, ProductSyncService,
 * WebhookHandlerService).
 */
final class SmokeTest extends TestCase
{
    public function testAutoloadResolves(): void
    {
        self::assertTrue(class_exists(\PHPUnit\Framework\TestCase::class));
    }
}
