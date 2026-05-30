<?php

declare(strict_types=1);

namespace QameraAi\Module\Sync;

use InvalidArgumentException;

/**
 * Mints the upstream `external_ref` strings for gallery images and their
 * packshots, layered on top of {@see ProductRefBuilder}. A single builder
 * is shared by both the `actionWatermark` hook-sync path and the on-demand
 * gallery picker so that a packshot's `source_image_ref` is always
 * byte-identical to its source image's stored `external_ref` (the hard
 * constraint that keeps Flow-B linking from failing `invalid_input`).
 *
 * Formats (D3):
 *   image    → `ps:<shop>:<prod>:image:<psImageId>`
 *   packshot → `ps:<shop>:<prod>:pack:<psImageId>`
 */
final class ExternalRefBuilder
{
    public function __construct(private readonly ProductRefBuilder $refBuilder)
    {
    }

    public function imageRef(int $idShop, int $idProduct, int $psImageId): string
    {
        return $this->scopedRef($idShop, $idProduct, 'image', $psImageId);
    }

    public function packshotRef(int $idShop, int $idProduct, int $psImageId): string
    {
        return $this->scopedRef($idShop, $idProduct, 'pack', $psImageId);
    }

    private function scopedRef(int $idShop, int $idProduct, string $kind, int $psImageId): string
    {
        if ($psImageId <= 0) {
            throw new InvalidArgumentException(
                'ExternalRefBuilder: psImageId must be a positive integer.'
            );
        }

        return sprintf('%s:%s:%d', $this->refBuilder->build($idShop, $idProduct), $kind, $psImageId);
    }
}
