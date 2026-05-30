<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Gallery;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use QameraAi\Module\Gallery\ProductImageFileResolver;
use QameraAi\Module\Gallery\ResolvedImageFile;
use RuntimeException;

final class ProductImageFileResolverTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->baseDir = sys_get_temp_dir() . '/qfix-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->baseDir)) {
            $this->rrmdir($this->baseDir);
        }
        parent::tearDown();
    }

    /**
     * @param array<int, string> $formats psImageId => image_format
     */
    private function resolver(array $formats = []): ProductImageFileResolver
    {
        $base = $this->baseDir;
        return new class ($base, $formats) extends ProductImageFileResolver {
            /** @param array<int, string> $formats */
            public function __construct(string $baseDir, private array $formats)
            {
                parent::__construct($baseDir);
            }

            protected function imageFormat(int $psImageId): string
            {
                return $this->formats[$psImageId] ?? 'jpg';
            }
        };
    }

    private function writeFixture(int $id, string $ext, string $bytes): string
    {
        // PrestaShop splits image ids one-digit-per-directory: 42 → 4/2/.
        $folder = $this->baseDir;
        foreach (str_split((string) $id) as $digit) {
            $folder .= '/' . $digit;
        }
        if (!is_dir($folder)) {
            mkdir($folder, 0777, true);
        }
        $path = $folder . '/' . $id . '.' . $ext;
        file_put_contents($path, $bytes);
        return $path;
    }

    public function testResolvesDigitSplitPathSizeAndContentType(): void
    {
        // 1x1 JPEG.
        $bytes = base64_decode(
            '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEB'
            . 'AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAf/AABEIAAEAAQMBIgACEQEDEQH/xAAfAAAB'
            . 'BQEBAQEBAQAAAAAAAAAAAQIDBAUGBwgJCgv/xAC1EAACAQMDAgQDBQUEBAAAAX0BAgMABBEF'
            . 'EiExQQYTUWEHInEUMoGRoQgjQrHBFVLR8CQzYnKCCQoWFxgZGiUmJygpKjU2Nzg5OkNERUZH'
            . 'SElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqy'
            . 's7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/aAAwDAQAC'
            . 'EQMRAD8A/v4ooooA/9k='
        );
        $expected = $this->writeFixture(42, 'jpg', $bytes);

        $resolved = $this->resolver()->resolve(42);

        self::assertInstanceOf(ResolvedImageFile::class, $resolved);
        self::assertSame($expected, $resolved->path);
        self::assertSame(strlen($bytes), $resolved->sizeBytes);
        self::assertSame('image/jpeg', $resolved->contentType);
    }

    public function testHonorsImageFormatExtension(): void
    {
        $bytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );
        $expected = $this->writeFixture(7, 'png', $bytes);

        $resolved = $this->resolver([7 => 'png'])->resolve(7);

        self::assertSame($expected, $resolved->path);
        self::assertSame('image/png', $resolved->contentType);
    }

    public function testMissingFileThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->resolver()->resolve(999);
    }

    public function testZeroIdThrowsInvalidArgument(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->resolver()->resolve(0);
    }

    private function rrmdir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
