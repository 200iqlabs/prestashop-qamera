<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Webhook\Event;

use PHPUnit\Framework\TestCase;
use QameraAi\Module\Webhook\Event\InvalidProductRefException;
use QameraAi\Module\Webhook\Event\ProductRefParser;

final class ProductRefParserTest extends TestCase
{
    public function testCanonicalRefParsesToTwoPositiveIntegers(): void
    {
        $ref = ProductRefParser::parse('ps:1:42');

        self::assertSame(1, $ref->shopId);
        self::assertSame(42, $ref->productId);
    }

    public function testImageSuffixedRefIsRejected(): void
    {
        // The webhook payload carries `job.product_ref` of shape
        // `ps:shop:product` — the registration-time `:image:` external_ref
        // is NOT a valid product_ref.
        $this->expectException(InvalidProductRefException::class);
        ProductRefParser::parse('ps:1:42:image:7');
    }

    public function testNonPsPrefixIsRejected(): void
    {
        $this->expectException(InvalidProductRefException::class);
        ProductRefParser::parse('qamera:1:42');
    }

    public function testTruncatedRefIsRejected(): void
    {
        $this->expectException(InvalidProductRefException::class);
        ProductRefParser::parse('ps:1');
    }

    public function testNonNumericSegmentIsRejected(): void
    {
        $this->expectException(InvalidProductRefException::class);
        ProductRefParser::parse('ps:abc:42');
    }

    public function testNegativeIntegerIsRejected(): void
    {
        $this->expectException(InvalidProductRefException::class);
        ProductRefParser::parse('ps:-1:42');
    }

    public function testZeroSegmentIsRejected(): void
    {
        $this->expectException(InvalidProductRefException::class);
        ProductRefParser::parse('ps:0:42');
    }

    public function testLeadingZeroIsRejected(): void
    {
        $this->expectException(InvalidProductRefException::class);
        ProductRefParser::parse('ps:01:42');
    }

    public function testLeadingWhitespaceIsRejected(): void
    {
        $this->expectException(InvalidProductRefException::class);
        ProductRefParser::parse(' ps:1:42');
    }

    public function testTrailingWhitespaceIsRejected(): void
    {
        $this->expectException(InvalidProductRefException::class);
        ProductRefParser::parse('ps:1:42 ');
    }
}
