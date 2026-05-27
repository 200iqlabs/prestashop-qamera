<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Webhook;

use PHPUnit\Framework\TestCase;
use QameraAi\Module\Webhook\MalformedSignatureException;
use QameraAi\Module\Webhook\SignatureHeaderParser;

final class SignatureHeaderParserTest extends TestCase
{
    private SignatureHeaderParser $parser;

    protected function setUp(): void
    {
        $this->parser = new SignatureHeaderParser();
    }

    public function testParsesSingleV1(): void
    {
        $parsed = $this->parser->parse('t=1716800000,v1=abcdef0123456789');

        self::assertSame(1716800000, $parsed->timestamp);
        self::assertSame(['abcdef0123456789'], $parsed->signatures);
    }

    public function testParsesTwoV1ValuesInOrder(): void
    {
        $parsed = $this->parser->parse('t=1716800000,v1=aaa,v1=bbb');

        self::assertSame(1716800000, $parsed->timestamp);
        self::assertSame(['aaa', 'bbb'], $parsed->signatures);
    }

    public function testIgnoresUnknownKeys(): void
    {
        $parsed = $this->parser->parse('t=1716800000,v2=zzz,v1=abc');

        self::assertSame(1716800000, $parsed->timestamp);
        self::assertSame(['abc'], $parsed->signatures);
    }

    public function testRejectsMissingTimestamp(): void
    {
        $this->expectException(MalformedSignatureException::class);
        $this->parser->parse('v1=abc');
    }

    public function testRejectsNonNumericTimestamp(): void
    {
        $this->expectException(MalformedSignatureException::class);
        $this->parser->parse('t=now,v1=abc');
    }

    public function testRejectsNegativeTimestamp(): void
    {
        $this->expectException(MalformedSignatureException::class);
        $this->parser->parse('t=-100,v1=abc');
    }

    public function testRejectsNoV1Values(): void
    {
        $this->expectException(MalformedSignatureException::class);
        $this->parser->parse('t=1716800000');
    }

    public function testRejectsDuplicateTimestamp(): void
    {
        $this->expectException(MalformedSignatureException::class);
        $this->parser->parse('t=1716800000,t=1716800001,v1=abc');
    }

    public function testRejectsTrailingComma(): void
    {
        $this->expectException(MalformedSignatureException::class);
        $this->parser->parse('t=1716800000,v1=abc,');
    }

    public function testRejectsWhitespaceInsideEntries(): void
    {
        $this->expectException(MalformedSignatureException::class);
        $this->parser->parse('t= 1716800000,v1=abc');
    }

    public function testRejectsEmptyString(): void
    {
        $this->expectException(MalformedSignatureException::class);
        $this->parser->parse('');
    }

    public function testRejectsEmptyV1Value(): void
    {
        $this->expectException(MalformedSignatureException::class);
        $this->parser->parse('t=1716800000,v1=');
    }

    public function testTrimsOuterWhitespace(): void
    {
        // Outer trim allowed (proxies may add it); inner whitespace rejected.
        $parsed = $this->parser->parse("  t=1716800000,v1=abc  ");
        self::assertSame(1716800000, $parsed->timestamp);
    }
}
