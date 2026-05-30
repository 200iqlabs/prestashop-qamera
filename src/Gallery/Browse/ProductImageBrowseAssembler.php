<?php

declare(strict_types=1);

namespace QameraAi\Module\Gallery\Browse;

use QameraAi\Module\Api\Dto\ProductDetailResponse;
use QameraAi\Module\Api\Dto\ProductPackshotDto;

/**
 * Builds the per-image browse tree from a {@see ProductDetailResponse}
 * (design D5): one {@see BrowseImage} row per upstream image, each carrying
 * the packshots whose `source_image_id` matches it. Packshots with a null or
 * unmatched source image fall into `orphanPackshots` (synthesized images, or
 * images lost to truncation). Session images are attached later by the lazy
 * jobs walk. Upstream truncation flags are surfaced verbatim (D9).
 */
final class ProductImageBrowseAssembler
{
    public function assemble(ProductDetailResponse $product): BrowseView
    {
        /** @var array<string, BrowseImage> $byId */
        $byId = [];
        $images = [];
        foreach ($product->images as $image) {
            $row = new BrowseImage(
                $image->id,
                $image->externalRef,
                $this->parsePsImageId($image->externalRef),
                $image->analysisStatus
            );
            $images[] = $row;
            $byId[$image->id] = $row;
        }

        $orphans = [];
        foreach ($product->packshots as $packshot) {
            $browsePackshot = $this->toBrowsePackshot($packshot);
            $sourceId = $packshot->sourceImageId;
            if ($sourceId !== null && isset($byId[$sourceId])) {
                $byId[$sourceId]->packshots[] = $browsePackshot;
            } else {
                $orphans[] = $browsePackshot;
            }
        }

        return new BrowseView(
            $images,
            $product->imagesTruncated,
            $product->packshotsTruncated,
            $orphans
        );
    }

    private function toBrowsePackshot(ProductPackshotDto $packshot): BrowsePackshot
    {
        return new BrowsePackshot(
            $packshot->id,
            $packshot->sourceImageId,
            $packshot->assetId,
            $packshot->generatedByJobId
        );
    }

    /**
     * Pulls the PrestaShop `id_image` out of a `ps:<shop>:<prod>:image:<id>`
     * external_ref. Returns null for a null ref or any other shape.
     */
    private function parsePsImageId(?string $externalRef): ?int
    {
        if ($externalRef === null) {
            return null;
        }
        if (preg_match('/:image:(\d+)$/', $externalRef, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }
}
