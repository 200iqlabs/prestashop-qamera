<?php

declare(strict_types=1);

namespace QameraAi\Module\Gallery;

/**
 * Immutable descriptor of a PrestaShop product-image file on local disk:
 * the absolute path the upload strategy PUTs, plus the content type and
 * byte size the presigned-upload request needs.
 */
final class ResolvedImageFile
{
    public function __construct(
        public readonly string $path,
        public readonly string $contentType,
        public readonly int $sizeBytes,
    ) {
    }
}
