<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Integration\Fixtures;

use Configuration;
use Image;
use Product;
use RuntimeException;

/**
 * Attaches a real `Image` row to a `TEST-`-prefixed product and copies
 * a known-existing source file into the layout that
 * `ProductImageSyncService::resolveImagePath` walks
 * (`_PS_PRODUCT_IMG_DIR_` + per-digit folder split + `<id>.jpg`).
 *
 * The default source `/var/www/html/img/p/lt.jpg` is a PrestaShop-
 * bundled flag asset present in every standard install of the dev
 * container — using it avoids checking in a binary fixture file.
 */
final class ImageFactory
{
    public static function attachImage(Product $product, ?string $sourcePath = null): Image
    {
        $source = $sourcePath ?? self::resolveDefaultSource();
        if (!is_file($source)) {
            throw new RuntimeException(
                sprintf('ImageFactory: source file %s not found in dev container.', $source)
            );
        }

        $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
        if ($idLang <= 0) {
            $idLang = 1;
        }

        $image = new Image();
        $image->id_product = (int) $product->id;
        $image->position = Image::getHighestPosition((int) $product->id) + 1;
        $image->cover = !Image::getCover((int) $product->id);
        $image->legend = [$idLang => 'TEST image'];

        if (!$image->add()) {
            throw new RuntimeException(
                sprintf(
                    'ImageFactory: failed to add image row for id_product=%d.',
                    (int) $product->id
                )
            );
        }

        if (!defined('_PS_PRODUCT_IMG_DIR_')) {
            throw new RuntimeException(
                'ImageFactory: _PS_PRODUCT_IMG_DIR_ is not defined — kernel not booted?'
            );
        }
        $base = (string) constant('_PS_PRODUCT_IMG_DIR_');
        $folder = $base . $image->getImgFolder();
        if (!is_dir($folder) && !mkdir($folder, 0o755, true) && !is_dir($folder)) {
            throw new RuntimeException('ImageFactory: failed to create image folder ' . $folder);
        }
        $target = $folder . ((int) $image->id) . '.jpg';
        if (!copy($source, $target)) {
            throw new RuntimeException(
                sprintf('ImageFactory: failed to copy %s -> %s.', $source, $target)
            );
        }

        return $image;
    }

    /**
     * Resolves the default source file via the same PrestaShop image-
     * root constant the kernel exposes after bootstrap. Honors the
     * `QAMERAAI_PS_ROOT` override implicitly: `_PS_IMG_DIR_` is computed
     * by `config/config.inc.php` relative to whichever PS root the
     * bootstrap loaded, so non-default container layouts work without
     * a hardcoded absolute path.
     */
    private static function resolveDefaultSource(): string
    {
        if (!defined('_PS_IMG_DIR_')) {
            throw new RuntimeException(
                'ImageFactory: _PS_IMG_DIR_ is not defined — kernel not booted?'
            );
        }
        return rtrim((string) constant('_PS_IMG_DIR_'), '/') . '/p/lt.jpg';
    }
}
