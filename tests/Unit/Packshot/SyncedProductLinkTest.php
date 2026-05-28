<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Packshot;

use PHPUnit\Framework\TestCase;
use QameraAi\Module\Packshot\SyncedProductLink;

/**
 * Covers the Phase 4.4 Generate-readiness gate hardening: canGenerate()
 * requires BOTH a registered image AND a `described` analysis status.
 * Any other combination keeps the row gated.
 */
final class SyncedProductLinkTest extends TestCase
{
    /**
     * @dataProvider canGenerateMatrix
     */
    public function testCanGenerateMatrix(
        ?string $qameraImageId,
        ?string $analysisStatus,
        bool $expected,
        ?string $expectedHint,
    ): void {
        $link = new SyncedProductLink(
            idLink: 1,
            idShop: 1,
            idProduct: 42,
            qameraImageId: $qameraImageId,
            qameraProductRef: 'ps:1:42',
            displayNameSnapshot: 'Widget',
            analysisStatus: $analysisStatus,
        );

        self::assertSame($expected, $link->canGenerate());
        self::assertSame($expectedHint, $link->getDisabledHint());
    }

    /**
     * @return array<string, array{?string, ?string, bool, ?string}>
     */
    public static function canGenerateMatrix(): array
    {
        return [
            'image + described enables generate'
                => ['img-uuid', 'described', true, null],
            'image + partial enables generate (multi-image forward-compat)'
                => ['img-uuid', 'partial', true, null],
            'image + processing blocks with processing hint'
                => ['img-uuid', 'processing', false, 'Image is being analysed…'],
            'image + pending blocks with pending hint'
                => ['img-uuid', 'pending', false, 'Waiting for image analysis…'],
            'image + error blocks with re-sync hint'
                => ['img-uuid', 'error', false, 'Image analysis failed — re-sync product'],
            'image + NULL analysis blocks with awaiting hint'
                => ['img-uuid', null, false, 'Awaiting analysis status — refresh'],
            'NULL image takes precedence over described analysis'
                => [null, 'described', false, 'Sync this product first'],
            'NULL image takes precedence over null analysis'
                => [null, null, false, 'Sync this product first'],
            'empty image string treated as NULL'
                => ['', 'described', false, 'Sync this product first'],
        ];
    }
}
