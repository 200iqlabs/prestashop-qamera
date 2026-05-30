<?php

declare(strict_types=1);

namespace QameraAi\Module\Gallery\Browse;

use QameraAi\Module\Api\Exception\ApiException;
use QameraAi\Module\Api\QameraApiClient;

/**
 * Assigns a {@see Thumbnail} to every browse image and packshot (design D7).
 * Sourcing per object kind:
 *
 *   - product image (PS origin)   → local PS file (`KIND_PS_IMAGE`).
 *   - ingested packshot           → its source image's local PS thumb.
 *   - generated packshot          → its generating job's image-output URL
 *                                   (`getJob`, memoised per job for the page).
 *   - synthesized image (no PS    → a related packshot's thumbnail, else a
 *     origin)                       labelled placeholder.
 *
 * Session images already carry their signed `url`, so they need no sourcing.
 * Packshots are sourced before images so a synthesized image can borrow a
 * packshot's resolved thumbnail.
 */
final class ThumbnailSourcer
{
    /** @var array<string, ?string> memoised job-id => first image-output url */
    private array $jobThumbCache = [];

    public function __construct(private readonly QameraApiClient $apiClient)
    {
    }

    public function applyTo(BrowseView $view): void
    {
        /** @var array<string, BrowseImage> $imagesById */
        $imagesById = [];
        foreach ($view->images as $image) {
            $imagesById[$image->imageId] = $image;
        }

        foreach ($view->images as $image) {
            foreach ($image->packshots as $packshot) {
                $packshot->thumbnail = $this->sourcePackshot($packshot, $imagesById);
            }
        }
        foreach ($view->orphanPackshots as $packshot) {
            $packshot->thumbnail = $this->sourcePackshot($packshot, $imagesById);
        }

        foreach ($view->images as $image) {
            $image->thumbnail = $this->sourceImage($image);
        }
    }

    /**
     * @param array<string, BrowseImage> $imagesById
     */
    private function sourcePackshot(BrowsePackshot $packshot, array $imagesById): Thumbnail
    {
        if (!$packshot->isGenerated()) {
            // Ingested packshot: asset == its source image, so reuse that
            // image's local PS thumbnail.
            $psImageId = $this->psImageIdFor($packshot->sourceImageId, $imagesById);
            return $psImageId !== null ? Thumbnail::psImage($psImageId) : Thumbnail::placeholder();
        }

        $url = $this->jobThumbUrl((string) $packshot->generatedByJobId);
        return $url !== null ? Thumbnail::url($url) : Thumbnail::placeholder();
    }

    private function sourceImage(BrowseImage $image): Thumbnail
    {
        if ($image->psImageId !== null) {
            return Thumbnail::psImage($image->psImageId);
        }

        // Synthesized image (no PS origin): borrow a related packshot's
        // already-resolved thumbnail, else placeholder.
        foreach ($image->packshots as $packshot) {
            $thumb = $packshot->thumbnail;
            if ($thumb !== null && $thumb->kind !== Thumbnail::KIND_PLACEHOLDER) {
                return $thumb;
            }
        }

        return Thumbnail::placeholder();
    }

    /**
     * @param array<string, BrowseImage> $imagesById
     */
    private function psImageIdFor(?string $sourceImageId, array $imagesById): ?int
    {
        if ($sourceImageId === null || !isset($imagesById[$sourceImageId])) {
            return null;
        }

        return $imagesById[$sourceImageId]->psImageId;
    }

    private function jobThumbUrl(string $jobId): ?string
    {
        if (array_key_exists($jobId, $this->jobThumbCache)) {
            return $this->jobThumbCache[$jobId];
        }

        $url = null;
        try {
            $job = $this->apiClient->getJob($jobId);
            foreach ($job->outputs as $output) {
                if (str_starts_with(strtolower($output->type), 'image/')) {
                    $url = $output->url;
                    break;
                }
            }
        } catch (ApiException $e) {
            $url = null;
        }

        return $this->jobThumbCache[$jobId] = $url;
    }
}
