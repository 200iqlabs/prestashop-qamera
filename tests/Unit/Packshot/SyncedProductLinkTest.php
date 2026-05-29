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
        ?string $qameraAssetId,
        ?string $analysisStatus,
        bool $expected,
        ?string $expectedHint,
    ): void {
        $link = new SyncedProductLink(
            idLink: 1,
            idShop: 1,
            idProduct: 42,
            qameraAssetId: $qameraAssetId,
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
            'asset + described enables generate'
                => ['asset-uuid', 'described', true, null],
            'asset + partial blocks generate (multi-image future will revisit)'
                => ['asset-uuid', 'partial', false, 'Some images still analysing — refresh'],
            'asset + processing blocks with processing hint'
                => ['asset-uuid', 'processing', false, 'Image is being analysed…'],
            'asset + pending blocks with pending hint'
                => ['asset-uuid', 'pending', false, 'Waiting for image analysis…'],
            'asset + error blocks with re-sync hint'
                => ['asset-uuid', 'error', false, 'Image analysis failed — re-sync product'],
            'asset + NULL analysis blocks with awaiting hint'
                => ['asset-uuid', null, false, 'Awaiting analysis status — refresh'],
            // 7.4 truth-table case: a NULL asset id (e.g. a row nulled by
            // upgrade-1.5.0.php) blocks Generate even when analysis is
            // 'described' — the missing storage asset id takes precedence.
            'NULL asset takes precedence over described analysis'
                => [null, 'described', false, 'Sync this product first'],
            'NULL asset takes precedence over null analysis'
                => [null, null, false, 'Sync this product first'],
            'empty asset string treated as NULL'
                => ['', 'described', false, 'Sync this product first'],
        ];
    }
}
