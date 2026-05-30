<?php

declare(strict_types=1);

namespace QameraAi\Module\Gallery\Browse;

use QameraAi\Module\Api\Dto\JobsListFilters;
use QameraAi\Module\Api\Dto\ProductPackshotDto;
use QameraAi\Module\Api\QameraApiClient;

/**
 * Resolves a product's photo-shoot session images by walking `GET /jobs`
 * (design D6). `GET /jobs` has no product or job-type filter, so the walk
 * pages by cursor and filters client-side to `job_type == photo_shoot` and
 * the target `product_ref`. Each session output is attributed to a product
 * image via `job.packshotAssetId → packshot.assetId → packshot.sourceImageId`.
 *
 * The walk is bounded: it scans at most `$maxJobs` jobs (a few pages) and
 * flags `capHit` when it stops short of exhausting the cursor, so the caller
 * can show a "showing recent sessions" notice. Intended to run lazily, only
 * when an accordion row is expanded.
 */
final class SessionImageResolver
{
    public function __construct(
        private readonly QameraApiClient $apiClient,
        private readonly int $pageSize = 50,
        private readonly int $maxJobs = 200,
    ) {
    }

    /**
     * @param ProductPackshotDto[] $packshots
     */
    public function resolve(string $productRef, array $packshots): SessionWalkResult
    {
        $assetToImageId = $this->buildAssetToImageMap($packshots);

        $sessions = [];
        $scanned = 0;
        $capHit = false;
        $cursor = null;

        while (true) {
            $page = $this->apiClient->listJobs(new JobsListFilters(
                null,
                null,
                null,
                $this->pageSize,
                $cursor
            ));

            foreach ($page->jobs as $job) {
                if ($scanned >= $this->maxJobs) {
                    $capHit = true;
                    break 2;
                }
                $scanned++;

                if ($job->jobType !== 'photo_shoot' || $job->productRef !== $productRef) {
                    continue;
                }
                $imageId = $assetToImageId[$job->packshotAssetId ?? ''] ?? null;
                if ($imageId === null) {
                    continue;
                }

                foreach ($job->outputs as $i => $output) {
                    if (!$this->isImageType($output->type)) {
                        continue;
                    }
                    $sessions[] = new BrowseSessionImage($imageId, $job->id, (int) $i, $output->url);
                }
            }

            if ($page->nextCursor === null) {
                break;
            }
            if ($scanned >= $this->maxJobs) {
                $capHit = true;
                break;
            }
            $cursor = $page->nextCursor;
        }

        return new SessionWalkResult($sessions, $capHit);
    }

    /**
     * @param ProductPackshotDto[] $packshots
     * @return array<string, string> assetId => sourceImageId
     */
    private function buildAssetToImageMap(array $packshots): array
    {
        $map = [];
        foreach ($packshots as $packshot) {
            if ($packshot->sourceImageId !== null) {
                $map[$packshot->assetId] = $packshot->sourceImageId;
            }
        }

        return $map;
    }

    private function isImageType(string $type): bool
    {
        return str_starts_with(strtolower($type), 'image/');
    }
}
