<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Api\Cache;

use PHPUnit\Framework\TestCase;
use QameraAi\Module\Api\Cache\ReferenceCache;

final class ReferenceCacheTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'qameraai-ref-cache-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->dir);
        parent::tearDown();
    }

    public function testGetReturnsNullOnMiss(): void
    {
        $cache = new ReferenceCache($this->dir);
        self::assertNull($cache->get('any-key', 300));
    }

    public function testRoundTripWithinTtlReturnsCachedValue(): void
    {
        $cache = new ReferenceCache($this->dir);
        $cache->set('k1', ['hello' => 'world']);

        self::assertSame(['hello' => 'world'], $cache->get('k1', 300));
    }

    public function testReturnsNullAfterTtlExpiry(): void
    {
        $cache = new ReferenceCache($this->dir);
        $cache->set('k2', 'value');

        // Backdate the cached file's stored_at by 400s so the TTL check
        // treats it as expired without sleeping the test.
        $path = $this->locateCacheFile($this->dir, 'k2');
        $raw = (string) file_get_contents($path);
        $envelope = unserialize($raw);
        $envelope['stored_at'] = time() - 400;
        file_put_contents($path, serialize($envelope));

        self::assertNull($cache->get('k2', 300));
    }

    public function testForgetRemovesEntry(): void
    {
        $cache = new ReferenceCache($this->dir);
        $cache->set('k3', 'value');
        self::assertSame('value', $cache->get('k3', 300));

        $cache->forget('k3');
        self::assertNull($cache->get('k3', 300));
    }

    public function testKeyIncludesShaSuffixOfApiKey(): void
    {
        $key1 = ReferenceCache::key('/ai-models', 'api-key-A');
        $key2 = ReferenceCache::key('/ai-models', 'api-key-B');
        self::assertNotSame($key1, $key2);
        self::assertStringStartsWith('qameraai:ref:/ai-models:', $key1);
        // SHA-256 truncated to 16 hex chars per spec
        self::assertMatchesRegularExpression(
            '/^qameraai:ref:\/ai-models:[0-9a-f]{16}$/',
            $key1
        );
    }

    public function testDifferentApiKeysGetDifferentEntries(): void
    {
        $cache = new ReferenceCache($this->dir);
        $cache->set(ReferenceCache::key('/pricing', 'A'), 'value-A');
        $cache->set(ReferenceCache::key('/pricing', 'B'), 'value-B');

        self::assertSame('value-A', $cache->get(ReferenceCache::key('/pricing', 'A'), 300));
        self::assertSame('value-B', $cache->get(ReferenceCache::key('/pricing', 'B'), 300));
    }

    public function testSetCreatesCacheDirIfMissing(): void
    {
        $nested = $this->dir . DIRECTORY_SEPARATOR . 'nested';
        $cache = new ReferenceCache($nested);
        $cache->set('k', 'v');

        self::assertSame('v', $cache->get('k', 300));
        self::assertDirectoryExists($nested);
    }

    private function locateCacheFile(string $dir, string $logicalKey): string
    {
        return $dir . DIRECTORY_SEPARATOR . hash('sha256', $logicalKey) . '.cache';
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
