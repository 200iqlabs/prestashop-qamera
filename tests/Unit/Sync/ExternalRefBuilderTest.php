<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Sync;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use QameraAi\Module\Sync\ExternalRefBuilder;
use QameraAi\Module\Sync\ProductRefBuilder;

final class ExternalRefBuilderTest extends TestCase
{
    private function builder(): ExternalRefBuilder
    {
        return new ExternalRefBuilder(new ProductRefBuilder());
    }

    public function testImageRefUsesProductScopedImageScheme(): void
    {
        self::assertSame('ps:1:42:image:7', $this->builder()->imageRef(1, 42, 7));
    }

    public function testPackshotRefUsesProductScopedPackScheme(): void
    {
        self::assertSame('ps:1:42:pack:7', $this->builder()->packshotRef(1, 42, 7));
    }

    public function testImageRefMatchesLegacyHookSyncFormat(): void
    {
        // Byte-identical to the format ProductImageSyncService minted inline:
        // sprintf('%s:image:%d', $productRef, $imageId).
        $productRef = (new ProductRefBuilder())->build(2, 99);
        self::assertSame($productRef . ':image:5', $this->builder()->imageRef(2, 99, 5));
    }

    public function testMultiShopDistinguishesSameImage(): void
    {
        $b = $this->builder();
        self::assertNotSame($b->imageRef(1, 42, 7), $b->imageRef(2, 42, 7));
    }

    public function testZeroImageIdRaisesInvalidArgument(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->builder()->imageRef(1, 42, 0);
    }

    public function testNegativeImageIdRaisesInvalidArgumentForPackshot(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->builder()->packshotRef(1, 42, -3);
    }

    public function testInvalidProductPropagatesFromProductRefBuilder(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->builder()->imageRef(1, 0, 7);
    }
}
