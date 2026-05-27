<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Dto;

use InvalidArgumentException;

/**
 * Value object for the `product_metadata` field on `POST /images` and
 * `POST /packshots`. Triggers upstream cascade-create of the product
 * when the `product_ref` is unknown to the server.
 *
 * Size constraints mirror the upstream `ProductMetadataSchema`. They
 * are enforced in the constructor so callers cannot construct an
 * invalid payload at runtime.
 */
final class ProductMetadata
{
    private const DISPLAY_NAME_MAX = 500;
    private const SKU_MAX = 100;
    private const DESCRIPTION_MAX = 5000;

    /**
     * @param array<string, mixed>|null $extra
     */
    public function __construct(
        public readonly string $displayName,
        public readonly ?string $sku = null,
        public readonly ?string $description = null,
        public readonly ?array $extra = null,
    ) {
        $len = self::length($displayName);
        if ($len < 1) {
            throw new InvalidArgumentException(
                'ProductMetadata: display_name must be at least 1 character.'
            );
        }
        if ($len > self::DISPLAY_NAME_MAX) {
            throw new InvalidArgumentException(sprintf(
                'ProductMetadata: display_name exceeds max length of %d characters.',
                self::DISPLAY_NAME_MAX
            ));
        }
        if ($sku !== null && self::length($sku) > self::SKU_MAX) {
            throw new InvalidArgumentException(sprintf(
                'ProductMetadata: sku exceeds max length of %d characters.',
                self::SKU_MAX
            ));
        }
        if ($description !== null && self::length($description) > self::DESCRIPTION_MAX) {
            throw new InvalidArgumentException(sprintf(
                'ProductMetadata: description exceeds max length of %d characters.',
                self::DESCRIPTION_MAX
            ));
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $payload = ['display_name' => $this->displayName];
        if ($this->sku !== null) {
            $payload['sku'] = $this->sku;
        }
        if ($this->description !== null) {
            $payload['description'] = $this->description;
        }
        if ($this->extra !== null) {
            $payload['extra'] = $this->extra;
        }

        return $payload;
    }

    private static function length(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value);
        }
        return strlen($value);
    }
}
