<?php

declare(strict_types=1);

namespace QameraAi\Module\Gallery;

use InvalidArgumentException;
use QameraAi\Module\Api\Dto\ProductMetadata;

/**
 * A single gallery-image ingest request: which PrestaShop image to push for
 * which product, and whether to register it as a product image (Flow A) or
 * as a packshot (image-then-packshot collapse).
 */
final class IngestItem
{
    public const ACTION_PRODUCT = 'product';
    public const ACTION_PACKSHOT = 'packshot';

    public function __construct(
        public readonly int $idShop,
        public readonly int $idProduct,
        public readonly int $psImageId,
        public readonly string $action,
        public readonly ?ProductMetadata $metadata = null,
    ) {
        if ($action !== self::ACTION_PRODUCT && $action !== self::ACTION_PACKSHOT) {
            throw new InvalidArgumentException(sprintf(
                'IngestItem: action must be "%s" or "%s", got "%s".',
                self::ACTION_PRODUCT,
                self::ACTION_PACKSHOT,
                $action
            ));
        }
    }

    public function isPackshot(): bool
    {
        return $this->action === self::ACTION_PACKSHOT;
    }
}
