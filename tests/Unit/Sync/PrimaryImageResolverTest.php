<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Sync;

use Image;
use PHPUnit\Framework\TestCase;
use QameraAi\Module\Sync\PrimaryImageResolver;

final class PrimaryImageResolverTest extends TestCase
{
    private PrimaryImageResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        Image::$covers = [];
        Image::$images = [];
        $this->resolver = new PrimaryImageResolver();
    }

    public function testCoverImageWinsOverHint(): void
    {
        Image::$covers[42] = ['id_image' => 100, 'cover' => 1];
        Image::$images[42] = [
            ['id_image' => 100, 'cover' => 1, 'position' => 1],
            ['id_image' => 99, 'cover' => 0, 'position' => 2],
        ];

        self::assertSame(100, $this->resolver->resolve(42, 99, 1));
    }

    public function testHintUsedWhenNoCover(): void
    {
        Image::$covers[42] = false;
        Image::$images[42] = [
            ['id_image' => 99, 'cover' => 0, 'position' => 1],
            ['id_image' => 101, 'cover' => 0, 'position' => 2],
        ];

        self::assertSame(99, $this->resolver->resolve(42, 99, 1));
    }

    public function testFirstByPositionFallback(): void
    {
        Image::$covers[42] = false;
        Image::$images[42] = [
            ['id_image' => 77, 'cover' => 0, 'position' => 1],
            ['id_image' => 78, 'cover' => 0, 'position' => 2],
        ];

        self::assertSame(77, $this->resolver->resolve(42, null, 1));
    }

    public function testNullReturnedForProductWithNoImages(): void
    {
        Image::$covers[42] = false;
        Image::$images[42] = [];

        self::assertNull($this->resolver->resolve(42, null, 1));
    }

    public function testHintForDifferentProductIgnored(): void
    {
        Image::$covers[42] = false;
        // Product 42's images list does NOT include 9999.
        Image::$images[42] = [
            ['id_image' => 100, 'cover' => 0, 'position' => 1],
        ];

        // Hint 9999 belongs to a different product — resolver ignores
        // it and falls through to first-by-position (100).
        self::assertSame(100, $this->resolver->resolve(42, 9999, 1));
    }
}
