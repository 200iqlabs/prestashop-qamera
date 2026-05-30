<?php

declare(strict_types=1);

namespace QameraAi\Module\Gallery\Browse;

/**
 * The assembled per-image browse tree for one product. `orphanPackshots`
 * holds packshots whose `source_image_id` matched no returned image (a
 * synthesized image, or an image lost to truncation); they render under a
 * separate "synthesized" bucket. `sessionsTruncated` is set when the lazy
 * jobs walk hit its cap before exhausting jobs.
 */
final class BrowseView
{
    /**
     * @param BrowseImage[]    $images
     * @param BrowsePackshot[] $orphanPackshots
     */
    public function __construct(
        public readonly array $images,
        public readonly bool $imagesTruncated,
        public readonly bool $packshotsTruncated,
        public readonly array $orphanPackshots = [],
        public bool $sessionsTruncated = false,
    ) {
    }
}
