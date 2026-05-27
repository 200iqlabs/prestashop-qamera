<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Api\Dto;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use QameraAi\Module\Api\Dto\ProductMetadata;

final class ProductMetadataTest extends TestCase
{
    public function testConstructorAcceptsValidValues(): void
    {
        $meta = new ProductMetadata('Widget', 'WDG-001', 'desc');

        self::assertSame(
            ['display_name' => 'Widget', 'sku' => 'WDG-001', 'description' => 'desc'],
            $meta->toPayload()
        );
    }

    public function testOmittedSkuAndDescriptionAreAbsentFromPayload(): void
    {
        $meta = new ProductMetadata('Widget');

        self::assertSame(['display_name' => 'Widget'], $meta->toPayload());
    }

    public function testDisplayNameAt500CharsAccepted(): void
    {
        $meta = new ProductMetadata(str_repeat('a', 500));

        self::assertSame(str_repeat('a', 500), $meta->displayName);
    }

    public function testDisplayNameAt501CharsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('display_name');

        new ProductMetadata(str_repeat('a', 501));
    }

    public function testSkuAt100CharsAccepted(): void
    {
        $meta = new ProductMetadata('n', str_repeat('a', 100));

        self::assertSame(str_repeat('a', 100), $meta->sku);
    }

    public function testSkuAt101CharsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('sku');

        new ProductMetadata('n', str_repeat('a', 101));
    }

    public function testDescriptionAt5000CharsAccepted(): void
    {
        $meta = new ProductMetadata('n', null, str_repeat('a', 5000));

        self::assertSame(str_repeat('a', 5000), $meta->description);
    }

    public function testDescriptionAt5001CharsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('description');

        new ProductMetadata('n', null, str_repeat('a', 5001));
    }

    public function testEmptyDisplayNameRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('display_name');

        new ProductMetadata('');
    }
}
