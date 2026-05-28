<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Webhook\Event;

use PHPUnit\Framework\TestCase;
use QameraAi\Module\Webhook\Event\InvalidExternalRefException;
use QameraAi\Module\Webhook\Event\PackshotExternalRefParser;

final class PackshotExternalRefParserTest extends TestCase
{
    public function testParsesCanonicalShape(): void
    {
        $ref = PackshotExternalRefParser::parse('ps:1:42:packshot:11111111-2222-3333-4444-555555555555');

        self::assertSame(1, $ref->shopId);
        self::assertSame(42, $ref->productId);
        self::assertSame('11111111-2222-3333-4444-555555555555', $ref->packshotUuid);
    }

    /**
     * @dataProvider provideInvalidRefs
     */
    public function testRejectsInvalidShapes(string $raw): void
    {
        $this->expectException(InvalidExternalRefException::class);
        PackshotExternalRefParser::parse($raw);
    }

    /**
     * @return iterable<string, array{0:string}>
     */
    public static function provideInvalidRefs(): iterable
    {
        yield 'wrong prefix' => ['x:1:42:packshot:11111111-2222-3333-4444-555555555555'];
        yield 'image segment instead of packshot' => ['ps:1:42:image:7'];
        yield 'shop id zero' => ['ps:0:42:packshot:11111111-2222-3333-4444-555555555555'];
        yield 'product id zero' => ['ps:1:0:packshot:11111111-2222-3333-4444-555555555555'];
        yield 'leading-zero shop' => ['ps:01:42:packshot:11111111-2222-3333-4444-555555555555'];
        yield 'truncated uuid' => ['ps:1:42:packshot:11111111-2222'];
        yield 'uppercase uuid' => ['ps:1:42:packshot:11111111-2222-3333-4444-AAAAAAAAAAAA'];
        yield 'extra trailing segment' => ['ps:1:42:packshot:11111111-2222-3333-4444-555555555555:extra'];
        yield 'whitespace around' => [' ps:1:42:packshot:11111111-2222-3333-4444-555555555555'];
        yield 'empty string' => [''];
    }
}
