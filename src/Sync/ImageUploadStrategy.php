<?php

declare(strict_types=1);

namespace QameraAi\Module\Sync;

/**
 * Strategy for making a local image file reachable by the Qamera AI
 * upstream. The returned string is the opaque `assetId` from upstream
 * — the caller passes it to `RegisterImageRequest::$assetId`.
 */
interface ImageUploadStrategy
{
    /**
     * @param string $localPath   absolute path to the image bytes on disk
     * @param string $filename    original filename to record with upstream
     * @param string $contentType MIME type of the bytes (e.g. `image/jpeg`)
     * @param int    $sizeBytes   size of the bytes on disk
     *
     * @return string upstream `assetId` to assign to `assetId` on the
     *                subsequent `RegisterImageRequest`
     */
    public function uploadImage(
        string $localPath,
        string $filename,
        string $contentType,
        int $sizeBytes
    ): string;
}
