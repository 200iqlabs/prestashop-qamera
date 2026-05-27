<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Webhook;

use PHPUnit\Framework\TestCase;
use QameraAi\Module\Tests\Support\FakeClock;
use QameraAi\Module\Webhook\ReplayGuard;

final class ReplayGuardTest extends TestCase
{
    private const NOW = 1716800000;

    public function testTimestampWithinWindowAccepted(): void
    {
        $guard = new ReplayGuard(new FakeClock(self::NOW));
        self::assertTrue($guard->isFresh(self::NOW));
        self::assertTrue($guard->isFresh(self::NOW - 1));
        self::assertTrue($guard->isFresh(self::NOW - 100));
    }

    public function testExactly300SecondsPastAccepted(): void
    {
        $guard = new ReplayGuard(new FakeClock(self::NOW));
        self::assertTrue($guard->isFresh(self::NOW - 300));
    }

    public function test301SecondsPastRejected(): void
    {
        $guard = new ReplayGuard(new FakeClock(self::NOW));
        self::assertFalse($guard->isFresh(self::NOW - 301));
    }

    public function testExactly60SecondsFutureAccepted(): void
    {
        $guard = new ReplayGuard(new FakeClock(self::NOW));
        self::assertTrue($guard->isFresh(self::NOW + 60));
    }

    public function test61SecondsFutureRejected(): void
    {
        $guard = new ReplayGuard(new FakeClock(self::NOW));
        self::assertFalse($guard->isFresh(self::NOW + 61));
    }
}
