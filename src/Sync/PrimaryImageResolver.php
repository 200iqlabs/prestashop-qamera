<?php

declare(strict_types=1);

namespace QameraAi\Module\Sync;

use Image;

/**
 * Picks the "primary" image id for a PrestaShop product. Returns the
 * resolved `id_image` int (NOT a PS `Image` instance — PS's
 * `Image::getCover` / `Image::getImages` return associative arrays
 * already, so callers consume the int directly).
 *
 * Resolution order:
 *   1. `Image::getCover($idProduct)` — operator-set cover wins.
 *   2. `$hintIdImage` from the `actionWatermark` hook params — but only
 *      when it points to an image actually belonging to that product.
 *   3. First image returned by `Image::getImages($idLang, $idProduct)`
 *      ordered by position.
 * Returns `null` when none of the above produce an image.
 */
class PrimaryImageResolver
{
    public function resolve(int $idProduct, ?int $hintIdImage, int $idLang): ?int
    {
        $cover = $this->getCover($idProduct);
        if (is_array($cover) && isset($cover['id_image'])) {
            $coverId = (int) $cover['id_image'];
            if ($coverId > 0) {
                return $coverId;
            }
        }

        $images = $this->getImages($idLang, $idProduct);

        if ($hintIdImage !== null && $hintIdImage > 0) {
            foreach ($images as $img) {
                if ((int) ($img['id_image'] ?? 0) === $hintIdImage) {
                    return $hintIdImage;
                }
            }
        }

        if ($images === []) {
            return null;
        }

        $first = $images[0];
        $firstId = (int) ($first['id_image'] ?? 0);
        return $firstId > 0 ? $firstId : null;
    }

    /**
     * Wraps `Image::getCover` so tests can override via a subclass or
     * the bundled stub. PS core returns `array|false`.
     *
     * @return array<string, mixed>|false
     */
    protected function getCover(int $idProduct)
    {
        return Image::getCover($idProduct);
    }

    /**
     * Wraps `Image::getImages` so tests can override.
     *
     * @return list<array<string, mixed>>
     */
    protected function getImages(int $idLang, int $idProduct): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = Image::getImages($idLang, $idProduct);
        return $rows;
    }
}
