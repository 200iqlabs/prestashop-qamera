<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Gallery\Browse;

use PHPUnit\Framework\TestCase;
use QameraAi\Module\Api\Dto\ProductDetailResponse;
use QameraAi\Module\Api\Dto\ProductImageDto;
use QameraAi\Module\Api\Dto\ProductPackshotDto;
use QameraAi\Module\Gallery\Browse\ProductImageBrowseAssembler;

final class ProductImageBrowseAssemblerTest extends TestCase
{
    private function image(string $id, ?string $externalRef, string $analysis = 'described'): ProductImageDto
    {
        return new ProductImageDto(
            $id,
            $externalRef,
            'prod-1',
            'asset-' . $id,
            1024,
            'image/jpeg',
            800,
            600,
            'sha-' . $id,
            $analysis,
            null,
            '2026-05-30T00:00:00Z'
        );
    }

    private function packshot(
        string $id,
        ?string $sourceImageId,
        string $assetId,
        ?string $generatedByJobId = null
    ): ProductPackshotDto {
        return new ProductPackshotDto(
            $id,
            'ps:1:42:pack:' . $id,
            'prod-1',
            $sourceImageId,
            $assetId,
            1024,
            'image/jpeg',
            800,
            600,
            'sha-' . $id,
            $generatedByJobId,
            '2026-05-30T00:00:00Z'
        );
    }

    /**
     * @param ProductImageDto[]    $images
     * @param ProductPackshotDto[] $packshots
     */
    private function product(
        array $images,
        array $packshots,
        bool $imagesTruncated = false,
        bool $packshotsTruncated = false
    ): ProductDetailResponse {
        return new ProductDetailResponse(
            'prod-1',
            'ps:1:42',
            'Widget',
            'WDG-1',
            null,
            [],
            null,
            '2026-05-30T00:00:00Z',
            '2026-05-30T00:00:00Z',
            $images,
            $imagesTruncated,
            $packshots,
            $packshotsTruncated
        );
    }

    public function testGroupsPackshotsUnderSourceImageWithCounts(): void
    {
        $product = $this->product(
            [
                $this->image('img-1', 'ps:1:42:image:100'),
                $this->image('img-2', 'ps:1:42:image:200'),
            ],
            [
                $this->packshot('pk-1', 'img-1', 'asset-img-1'),
                $this->packshot('pk-2', 'img-1', 'asset-gen', 'job-9'),
                $this->packshot('pk-3', 'img-2', 'asset-img-2'),
            ]
        );

        $view = (new ProductImageBrowseAssembler())->assemble($product);

        self::assertCount(2, $view->images);
        $first = $view->images[0];
        self::assertSame('img-1', $first->imageId);
        self::assertSame(100, $first->psImageId);
        self::assertCount(2, $first->packshots);
        self::assertSame(2, $first->packshotCount());
        self::assertSame(0, $first->sessionCount());

        $second = $view->images[1];
        self::assertSame(200, $second->psImageId);
        self::assertSame(1, $second->packshotCount());
    }

    public function testGeneratedVsIngestedPackshotImportability(): void
    {
        $product = $this->product(
            [$this->image('img-1', 'ps:1:42:image:100')],
            [
                $this->packshot('pk-ingested', 'img-1', 'asset-img-1'),
                $this->packshot('pk-generated', 'img-1', 'asset-gen', 'job-9'),
            ]
        );

        $view = (new ProductImageBrowseAssembler())->assemble($product);
        $packshots = $view->images[0]->packshots;

        $byId = [];
        foreach ($packshots as $p) {
            $byId[$p->packshotId] = $p;
        }

        self::assertFalse($byId['pk-ingested']->isGenerated());
        self::assertFalse($byId['pk-ingested']->isImportable());
        self::assertTrue($byId['pk-generated']->isGenerated());
        self::assertTrue($byId['pk-generated']->isImportable());
        self::assertSame('job-9', $byId['pk-generated']->generatedByJobId);
    }

    public function testTruncationFlagsSurfaced(): void
    {
        $product = $this->product(
            [$this->image('img-1', 'ps:1:42:image:100')],
            [$this->packshot('pk-1', 'img-1', 'asset-img-1')],
            true,
            true
        );

        $view = (new ProductImageBrowseAssembler())->assemble($product);

        self::assertTrue($view->imagesTruncated);
        self::assertTrue($view->packshotsTruncated);
    }

    public function testOrphanPackshotWithUnmatchedSourceImageBucketed(): void
    {
        // Packshot points at an image not present in the (truncated) image set,
        // or a null source — these are "synthesized" and grouped separately.
        $product = $this->product(
            [$this->image('img-1', 'ps:1:42:image:100')],
            [
                $this->packshot('pk-1', 'img-1', 'asset-img-1'),
                $this->packshot('pk-orphan', 'img-missing', 'asset-x', 'job-7'),
                $this->packshot('pk-null', null, 'asset-y', 'job-8'),
            ]
        );

        $view = (new ProductImageBrowseAssembler())->assemble($product);

        self::assertCount(1, $view->images[0]->packshots);
        self::assertCount(2, $view->orphanPackshots);
        $orphanIds = array_map(static fn ($p) => $p->packshotId, $view->orphanPackshots);
        self::assertEqualsCanonicalizing(['pk-orphan', 'pk-null'], $orphanIds);
    }

    public function testNullExternalRefYieldsNullPsImageId(): void
    {
        $product = $this->product(
            [$this->image('img-1', null)],
            []
        );

        $view = (new ProductImageBrowseAssembler())->assemble($product);

        self::assertNull($view->images[0]->psImageId);
    }
}
