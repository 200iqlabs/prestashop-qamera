<?php

declare(strict_types=1);

namespace QameraAi\Module\Packshot\Output;

use Image;
use ImageManager;
use ImageType;
use RuntimeException;
use Throwable;
use Tools;

/**
 * Writes a remote image URL into the PrestaShop product gallery, mirroring
 * `AdminImportController::copyImg()` — create `ps_image`, append at the end
 * of the gallery (never cover), associate to the shop, download + validate,
 * and resize into the base file plus every product `ImageType` derivative.
 * Deliberately fires NO watermark hook (the asset is already finished, and
 * firing it could re-enter the upload-sync path).
 *
 * All PrestaShop static access lives behind protected seams so the
 * orchestration is unit-testable without a live PS runtime (same precedent
 * as {@see \QameraAi\Module\Sync\PrimaryImageResolver}). The thin seam
 * bodies are exercised in PS smoke (§8).
 */
class GalleryImageWriter
{
    /**
     * Import one image. Returns the new `id_image`. Throws on any failure
     * (download, not-a-real-image, resize, persist) — the caller records a
     * per-output failure and continues with the rest of the set. A
     * half-created image row is removed before the exception propagates so a
     * failed import never leaves a dangling, image-less `ps_image`.
     *
     * @throws Throwable
     */
    public function importImage(int $idProduct, int $idShop, string $url): int
    {
        $position = $this->highestPosition($idProduct) + 1;
        $idImage = $this->createImageRow($idProduct, $position);

        $tmp = null;
        try {
            $this->associate($idImage, $idShop);

            $tmp = $this->downloadToTemp($url);
            if (!$this->isRealImage($tmp)) {
                throw new RuntimeException('Downloaded output is not a valid image: ' . $url);
            }

            $this->resizeInto($idImage, $tmp);

            return $idImage;
        } catch (Throwable $e) {
            $this->deleteImageRow($idImage);
            throw $e;
        } finally {
            if ($tmp !== null) {
                $this->discardTemp($tmp);
            }
        }
    }

    /**
     * Resize the temp source into the base file plus every product image
     * type derivative, using the split-directory layout.
     */
    private function resizeInto(int $idImage, string $tmpPath): void
    {
        $base = $this->pathForCreation($idImage);

        if (!$this->resizeFile($tmpPath, $base . '.jpg', null, null)) {
            throw new RuntimeException('Failed to write base image file for id_image=' . $idImage);
        }

        foreach ($this->imageTypes() as $type) {
            $name = (string) ($type['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $this->resizeFile(
                $tmpPath,
                $base . '-' . $name . '.jpg',
                isset($type['width']) ? (int) $type['width'] : null,
                isset($type['height']) ? (int) $type['height'] : null,
            );
        }
    }

    // --- PrestaShop seams (overridden in unit tests) --------------------

    protected function highestPosition(int $idProduct): int
    {
        return (int) Image::getHighestPosition($idProduct);
    }

    /**
     * Create the `ps_image` row, appended at `$position`, NEVER cover (D6 —
     * the operator's real product photo keeps the cover slot). Returns the
     * new id_image.
     */
    protected function createImageRow(int $idProduct, int $position): int
    {
        $image = new Image();
        $image->id_product = $idProduct;
        $image->position = $position;
        $image->cover = false;
        if (!$image->add()) {
            throw new RuntimeException('Failed to create ps_image for id_product=' . $idProduct);
        }
        return (int) $image->id;
    }

    protected function associate(int $idImage, int $idShop): void
    {
        $image = new Image($idImage);
        $image->associateTo([$idShop]);
    }

    protected function downloadToTemp(string $url): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'qameraimg');
        if ($tmp === false) {
            throw new RuntimeException('Could not allocate a temp file for download');
        }
        if (!Tools::copy($url, $tmp)) {
            @unlink($tmp);
            throw new RuntimeException('Failed to download output: ' . $url);
        }
        return $tmp;
    }

    protected function isRealImage(string $path): bool
    {
        return (bool) ImageManager::isRealImage($path);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function imageTypes(): array
    {
        /** @var list<array<string, mixed>> $types */
        $types = ImageType::getImagesTypes('products');
        return $types;
    }

    protected function pathForCreation(int $idImage): string
    {
        $image = new Image($idImage);
        return (string) $image->getPathForCreation();
    }

    protected function resizeFile(string $src, string $dest, ?int $width, ?int $height): bool
    {
        if ($width !== null && $height !== null) {
            return (bool) ImageManager::resize($src, $dest, $width, $height);
        }
        return (bool) ImageManager::resize($src, $dest);
    }

    protected function discardTemp(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    protected function deleteImageRow(int $idImage): void
    {
        $image = new Image($idImage);
        $image->delete();
    }
}
