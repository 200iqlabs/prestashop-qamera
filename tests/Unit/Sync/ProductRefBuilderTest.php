<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Sync;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use QameraAi\Module\Sync\ProductRefBuilder;

final class ProductRefBuilderTest extends TestCase
{
    public function testTypicalPairReturnsPsFormat(): void
    {
        self::assertSame('ps:1:42', (new ProductRefBuilder())->build(1, 42));
    }

    public function testMultiShopDistinguishesSameProduct(): void
    {
        $builder = new ProductRefBuilder();
        self::assertNotSame($builder->build(1, 42), $builder->build(2, 42));
    }

    public function testZeroShopRaisesInvalidArgument(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ProductRefBuilder())->build(0, 42);
    }

    public function testNegativeShopRaisesInvalidArgument(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ProductRefBuilder())->build(-1, 42);
    }

    public function testZeroProductRaisesInvalidArgument(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ProductRefBuilder())->build(1, 0);
    }

    public function testNegativeProductRaisesInvalidArgument(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ProductRefBuilder())->build(1, -5);
    }
}
