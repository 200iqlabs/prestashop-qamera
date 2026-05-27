<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Sync;

use PHPUnit\Framework\TestCase;
use QameraAi\Module\Sync\InMemoryDedupCache;

final class InMemoryDedupCacheTest extends TestCase
{
    public function testFirstSeenReturnsFalseAndSubsequentReturnsTrue(): void
    {
        $cache = new InMemoryDedupCache();

        self::assertFalse($cache->seen('42:99'));
        self::assertTrue($cache->seen('42:99'));
        self::assertTrue($cache->seen('42:99'));
    }

    public function testDistinctKeysAreIndependent(): void
    {
        $cache = new InMemoryDedupCache();

        self::assertFalse($cache->seen('42:99'));
        self::assertFalse($cache->seen('42:100'));
        self::assertFalse($cache->seen('43:99'));

        self::assertTrue($cache->seen('42:99'));
        self::assertTrue($cache->seen('42:100'));
        self::assertTrue($cache->seen('43:99'));
    }

    public function testEmptyStringKeyIsTreatedLikeAnyOtherKey(): void
    {
        $cache = new InMemoryDedupCache();

        self::assertFalse($cache->seen(''));
        self::assertTrue($cache->seen(''));
    }
}
