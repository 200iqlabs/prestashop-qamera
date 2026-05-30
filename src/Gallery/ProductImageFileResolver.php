<?php

declare(strict_types=1);

namespace QameraAi\Module\Gallery;

use Image;
use InvalidArgumentException;
use RuntimeException;

/**
 * Resolves a PrestaShop product-image id to the on-disk base file the
 * gallery picker uploads upstream. Mirrors the digit-split folder layout
 * (`_PS_PRODUCT_IMG_DIR_` + `4/2/42.jpg`) that
 * {@see \QameraAi\Module\Sync\ProductImageSyncService} already uses, but
 * exposes path + content type + size as a value object so the on-demand
 * ingest path can feed `PresignedImageUploadStrategy::uploadImage`.
 *
 * The image extension is taken from the PS `Image` record's `image_format`
 * (falling back to `jpg`), with a filesystem probe across the known
 * formats so a stale/wrong format guess still resolves the real file.
 */
class ProductImageFileResolver
{
    /** Extensions probed when the declared format does not match a file. */
    private const FALLBACK_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    private const CONTENT_TYPES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
    ];

    private readonly string $baseDir;

    public function __construct(?string $baseDir = null)
    {
        $this->baseDir = $baseDir ?? (defined('_PS_PRODUCT_IMG_DIR_')
            ? (string) constant('_PS_PRODUCT_IMG_DIR_')
            : '');
    }

    public function resolve(int $psImageId): ResolvedImageFile
    {
        if ($psImageId <= 0) {
            throw new InvalidArgumentException(
                'ProductImageFileResolver: psImageId must be a positive integer.'
            );
        }

        $folder = $this->imageFolder($psImageId);
        $path = $this->locateFile($folder, $psImageId);
        if ($path === null) {
            throw new RuntimeException(sprintf(
                'ProductImageFileResolver: no image file found for id_image=%d under %s.',
                $psImageId,
                $folder
            ));
        }

        $size = @filesize($path);

        return new ResolvedImageFile(
            $path,
            $this->contentTypeFor($path),
            is_int($size) && $size > 0 ? $size : 0
        );
    }

    /**
     * Looks up the PS image format (jpg/png/webp/…). Overridable in tests
     * to avoid booting the PS `Image` class.
     */
    protected function imageFormat(int $psImageId): string
    {
        $image = new Image($psImageId);
        $format = $image->image_format ?? null;

        return is_string($format) && $format !== '' ? $format : 'jpg';
    }

    private function locateFile(string $folder, int $psImageId): ?string
    {
        $declared = strtolower($this->imageFormat($psImageId));
        $candidates = array_values(array_unique(
            array_merge([$declared], self::FALLBACK_EXTENSIONS)
        ));

        foreach ($candidates as $ext) {
            $path = $folder . '/' . $psImageId . '.' . $ext;
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    private function imageFolder(int $psImageId): string
    {
        $base = rtrim($this->baseDir, '/');
        foreach (str_split((string) $psImageId) as $digit) {
            $base .= '/' . $digit;
        }

        return $base;
    }

    private function contentTypeFor(string $path): string
    {
        if (function_exists('mime_content_type')) {
            $detected = @mime_content_type($path);
            if (is_string($detected) && str_starts_with($detected, 'image/')) {
                return $detected;
            }
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return self::CONTENT_TYPES[$ext] ?? 'image/jpeg';
    }
}
