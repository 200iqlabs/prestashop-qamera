<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Api\Internal;

use PHPUnit\Framework\TestCase;
use QameraAi\Module\Api\Internal\HeaderBuilder;

final class HeaderBuilderTest extends TestCase
{
    public function testBaseHeadersCarryRequiredKeys(): void
    {
        $builder = new HeaderBuilder('mk_live_test', 'QameraAi-PrestaShop-Module/1.0.0 (9.0.0)', 'pl');
        $headers = $builder->baseHeaders();

        self::assertSame('mk_live_test', $headers['X-Api-Key']);
        self::assertSame('QameraAi-PrestaShop-Module/1.0.0 (9.0.0)', $headers['User-Agent']);
        self::assertSame('pl', $headers['Accept-Language']);
        self::assertSame('application/json', $headers['Accept']);
    }

    public function testUserAgentMatchesSpecRegex(): void
    {
        $builder = new HeaderBuilder('mk_live_test', 'QameraAi-PrestaShop-Module/2.3.4 (9.0.0)', 'en');
        $headers = $builder->baseHeaders();

        self::assertMatchesRegularExpression(
            '/^QameraAi-PrestaShop-Module\/\d+\.\d+\.\d+ \([^)]+\)$/',
            $headers['User-Agent'],
        );
    }
}
