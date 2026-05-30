<?php

declare(strict_types=1);

namespace QameraAi\Module\Gallery\Browse;

/**
 * A photo-shoot session output (one job output) grouped under the product
 * image it was generated from. `url` is the signed job-output URL used both
 * as the thumbnail and as the import source; `(jobId, outputIndex)` keys the
 * per-output add-to-gallery import.
 */
final class BrowseSessionImage
{
    public function __construct(
        public readonly string $imageId,
        public readonly string $jobId,
        public readonly int $outputIndex,
        public readonly string $url,
    ) {
    }
}
