<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Packshot\Output;

use PHPUnit\Framework\TestCase;
use QameraAi\Module\Tests\Support\RecordingGalleryImageWriter;

final class GalleryImageWriterTest extends TestCase
{
    public function testAppendsAtHighestPositionPlusOneAndReturnsNewId(): void
    {
        $w = new RecordingGalleryImageWriter();
        $w->highestPosition = 3;
        $w->newImageId = 200;
        $w->types = [];

        $id = $w->importImage(42, 1, 'https://cdn/scene.jpg');

        self::assertSame(200, $id);
        self::assertSame([['idProduct' => 42, 'position' => 4]], $w->created);
        self::assertSame([], $w->deleted);
    }

    public function testAssociatesNewImageToShop(): void
    {
        $w = new RecordingGalleryImageWriter();
        $w->newImageId = 200;

        $w->importImage(42, 2, 'https://cdn/scene.jpg');

        self::assertSame([['idImage' => 200, 'idShop' => 2]], $w->associated);
    }

    public function testResizesBaseFileAndEveryProductImageType(): void
    {
        $w = new RecordingGalleryImageWriter();
        $w->newImageId = 200;
        $w->types = [
            ['type' => 'home', 'name' => 'home_default', 'width' => 250, 'height' => 250],
            ['type' => 'large', 'name' => 'large_default', 'width' => 800, 'height' => 800],
        ];

        $w->importImage(42, 1, 'https://cdn/scene.jpg');

        // Base file (no width/height) + one resize per image type.
        self::assertCount(3, $w->resized);
        self::assertSame('/img/p/200.jpg', $w->resized[0]['dest']);
        self::assertNull($w->resized[0]['width']);
        self::assertSame('/img/p/200-home_default.jpg', $w->resized[1]['dest']);
        self::assertSame(250, $w->resized[1]['width']);
        self::assertSame('/img/p/200-large_default.jpg', $w->resized[2]['dest']);
        self::assertSame(800, $w->resized[2]['width']);
    }

    public function testRejectsNonRealImageAndCleansUp(): void
    {
        $w = new RecordingGalleryImageWriter();
        $w->newImageId = 200;
        $w->isReal = false;

        try {
            $w->importImage(42, 1, 'https://cdn/not-an-image');
            self::fail('expected an exception');
        } catch (\Throwable $e) {
            self::assertStringContainsString('not a valid image', $e->getMessage());
        }

        // The half-created image row is removed; nothing is resized.
        self::assertSame([200], $w->deleted);
        self::assertSame([], $w->resized);
    }

    public function testDownloadFailureCleansUpAndPropagates(): void
    {
        $w = new RecordingGalleryImageWriter();
        $w->newImageId = 200;
        $w->throwOnDownload = true;

        $this->expectException(\RuntimeException::class);
        try {
            $w->importImage(42, 1, 'https://cdn/scene.jpg');
        } finally {
            self::assertSame([200], $w->deleted);
        }
    }
}
