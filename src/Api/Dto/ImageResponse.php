<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Dto;

/**
 * Unwrapped single item from upstream `{results:[…]}` after a
 * `POST /images` bulk-of-1 dispatch. `status` is `'created'` for a fresh
 * row or `'existing'` when the (installation_id, external_ref) lookup
 * found a prior registration.
 */
final class ImageResponse
{
    public function __construct(
        public readonly string $externalRef,
        public readonly string $productId,
        public readonly string $imageId,
        public readonly string $status,
    ) {
    }
}
