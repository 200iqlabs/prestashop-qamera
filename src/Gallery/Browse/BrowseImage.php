<?php

declare(strict_types=1);

namespace QameraAi\Module\Gallery\Browse;

/**
 * One product image accordion row: the image itself plus the packshots
 * grouped under it and (lazily, on expand) its photo-shoot session images.
 * `psImageId` is parsed from the `ps:<shop>:<prod>:image:<id>` external_ref
 * when present, so the thumbnail can be sourced from the local PS file.
 */
final class BrowseImage
{
    public ?Thumbnail $thumbnail = null;

    /** @var BrowseSessionImage[] */
    public array $sessionImages = [];

    /**
     * @param BrowsePackshot[] $packshots
     */
    public function __construct(
        public readonly string $imageId,
        public readonly ?string $externalRef,
        public readonly ?int $psImageId,
        public readonly string $analysisStatus,
        public array $packshots = [],
    ) {
    }

    public function packshotCount(): int
    {
        return count($this->packshots);
    }

    public function sessionCount(): int
    {
        return count($this->sessionImages);
    }
}
