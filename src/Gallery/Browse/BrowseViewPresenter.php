<?php

declare(strict_types=1);

namespace QameraAi\Module\Gallery\Browse;

/**
 * Serialises an assembled {@see BrowseView} to the JSON shape the browse
 * accordion JS consumes. Thumbnail descriptors are rendered to concrete URLs
 * via the supplied resolver (the controller passes one backed by the PS image
 * link); session images and generated packshots carry an `importable` flag
 * that drives the "Add to product gallery" affordance (D11).
 */
final class BrowseViewPresenter
{
    /**
     * @param callable(?Thumbnail):?string $thumbUrl
     * @return array<string, mixed>
     */
    public function present(BrowseView $view, callable $thumbUrl): array
    {
        $images = [];
        foreach ($view->images as $image) {
            $images[] = $this->presentImage($image, $thumbUrl);
        }

        $orphans = [];
        foreach ($view->orphanPackshots as $packshot) {
            $orphans[] = $this->presentPackshot($packshot, $thumbUrl);
        }

        return [
            'found' => true,
            'images_truncated' => $view->imagesTruncated,
            'packshots_truncated' => $view->packshotsTruncated,
            'sessions_truncated' => $view->sessionsTruncated,
            'images' => $images,
            'orphan_packshots' => $orphans,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentNotFound(): array
    {
        return [
            'found' => false,
            'images_truncated' => false,
            'packshots_truncated' => false,
            'sessions_truncated' => false,
            'images' => [],
            'orphan_packshots' => [],
        ];
    }

    /**
     * @param callable(?Thumbnail):?string $thumbUrl
     * @return array<string, mixed>
     */
    private function presentImage(BrowseImage $image, callable $thumbUrl): array
    {
        $packshots = [];
        foreach ($image->packshots as $packshot) {
            $packshots[] = $this->presentPackshot($packshot, $thumbUrl);
        }

        $sessions = [];
        foreach ($image->sessionImages as $session) {
            $sessions[] = $this->presentSession($session);
        }

        return [
            'image_id' => $image->imageId,
            'external_ref' => $image->externalRef,
            'ps_image_id' => $image->psImageId,
            'analysis_status' => $image->analysisStatus,
            'thumbnail_url' => $thumbUrl($image->thumbnail),
            'packshot_count' => $image->packshotCount(),
            'session_count' => $image->sessionCount(),
            'packshots' => $packshots,
            'sessions' => $sessions,
        ];
    }

    /**
     * @param callable(?Thumbnail):?string $thumbUrl
     * @return array<string, mixed>
     */
    private function presentPackshot(BrowsePackshot $packshot, callable $thumbUrl): array
    {
        return [
            'packshot_id' => $packshot->packshotId,
            'source_image_id' => $packshot->sourceImageId,
            'generated_by_job_id' => $packshot->generatedByJobId,
            'is_generated' => $packshot->isGenerated(),
            'importable' => $packshot->isImportable(),
            'thumbnail_url' => $thumbUrl($packshot->thumbnail),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentSession(BrowseSessionImage $session): array
    {
        return [
            'image_id' => $session->imageId,
            'job_id' => $session->jobId,
            'output_index' => $session->outputIndex,
            'url' => $session->url,
            // Session images are always job-output-backed → importable (D11).
            'importable' => true,
        ];
    }
}
