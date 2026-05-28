<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Webhook\Event;

use PHPUnit\Framework\TestCase;
use QameraAi\Module\Webhook\Event\ExternalRefParser;
use QameraAi\Module\Webhook\Event\InvalidExternalRefException;

final class ExternalRefParserTest extends TestCase
{
    public function testCanonicalRefParsesToThreePositiveIntegers(): void
    {
        $ref = ExternalRefParser::parse('ps:1:42:image:7');

        self::assertSame(1, $ref->shopId);
        self::assertSame(42, $ref->productId);
        self::assertSame(7, $ref->imageId);
    }

    public function testNonPsPrefixIsRejected(): void
    {
        $this->expectException(InvalidExternalRefException::class);
        ExternalRefParser::parse('qamera:1:42:image:7');
    }

    public function testTruncatedRefIsRejected(): void
    {
        $this->expectException(InvalidExternalRefException::class);
        ExternalRefParser::parse('ps:1:42');
    }

    public function testNonNumericSegmentIsRejected(): void
    {
        $this->expectException(InvalidExternalRefException::class);
        ExternalRefParser::parse('ps:abc:42:image:7');
    }

    public function testNegativeIntegerIsRejected(): void
    {
        $this->expectException(InvalidExternalRefException::class);
        ExternalRefParser::parse('ps:-1:42:image:7');
    }

    public function testLeadingWhitespaceIsRejected(): void
    {
        $this->expectException(InvalidExternalRefException::class);
        ExternalRefParser::parse(' ps:1:42:image:7');
    }

    public function testTrailingWhitespaceIsRejected(): void
    {
        $this->expectException(InvalidExternalRefException::class);
        ExternalRefParser::parse('ps:1:42:image:7 ');
    }

    public function testZeroSegmentIsRejected(): void
    {
        $this->expectException(InvalidExternalRefException::class);
        ExternalRefParser::parse('ps:0:42:image:7');
    }

    public function testLeadingZeroIsRejected(): void
    {
        // Strict: "01" is rejected so that downstream code never compares
        // ints derived from a ref against ints from PS DB columns and
        // disagrees on equality due to canonicalisation surprises.
        $this->expectException(InvalidExternalRefException::class);
        ExternalRefParser::parse('ps:01:42:image:7');
    }

    public function testNonImageLiteralIsRejected(): void
    {
        $this->expectException(InvalidExternalRefException::class);
        ExternalRefParser::parse('ps:1:42:packshot:7');
    }
}
