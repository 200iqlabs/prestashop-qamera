<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Support;

use QameraAi\Module\Packshot\Output\GalleryImageWriter;

/**
 * Test double for {@see GalleryImageWriter}: overrides every PrestaShop
 * seam so the public `importImage()` orchestration is exercised without a
 * live PS runtime, and records the calls + drives scripted return values /
 * failures. Same precedent as the PrimaryImageResolver subclass stubs.
 */
final class RecordingGalleryImageWriter extends GalleryImageWriter
{
    public int $highestPosition = 0;
    public int $newImageId = 0;
    public bool $isReal = true;
    public bool $throwOnDownload = false;

    /** @var list<array{type:string, name:string, width:int, height:int}> */
    public array $types = [];

    /** @var list<array{idProduct:int, position:int}> */
    public array $created = [];
    /** @var list<array{idImage:int, idShop:int}> */
    public array $associated = [];
    /** @var list<string> */
    public array $downloaded = [];
    /** @var list<array{src:string, dest:string, width:?int, height:?int}> */
    public array $resized = [];
    /** @var list<int> */
    public array $deleted = [];

    protected function highestPosition(int $idProduct): int
    {
        return $this->highestPosition;
    }

    protected function createImageRow(int $idProduct, int $position): int
    {
        $this->created[] = ['idProduct' => $idProduct, 'position' => $position];
        return $this->newImageId;
    }

    protected function associate(int $idImage, int $idShop): void
    {
        $this->associated[] = ['idImage' => $idImage, 'idShop' => $idShop];
    }

    protected function downloadToTemp(string $url): string
    {
        if ($this->throwOnDownload) {
            throw new \RuntimeException('download failed: ' . $url);
        }
        $this->downloaded[] = $url;
        return '/tmp/fake-' . md5($url);
    }

    protected function isRealImage(string $path): bool
    {
        return $this->isReal;
    }

    protected function discardTemp(string $path): void
    {
        // no-op in tests (real body @unlinks)
    }

    /**
     * @return list<array{type:string, name:string, width:int, height:int}>
     */
    protected function imageTypes(): array
    {
        return $this->types;
    }

    protected function pathForCreation(int $idImage): string
    {
        return '/img/p/' . $idImage;
    }

    protected function resizeFile(string $src, string $dest, ?int $width, ?int $height): bool
    {
        $this->resized[] = ['src' => $src, 'dest' => $dest, 'width' => $width, 'height' => $height];
        return true;
    }

    protected function deleteImageRow(int $idImage): void
    {
        $this->deleted[] = $idImage;
    }
}
