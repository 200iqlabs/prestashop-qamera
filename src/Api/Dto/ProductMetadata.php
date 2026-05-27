<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Dto;

/**
 * Optional metadata attached to a `registerImage` / `registerPackshot`
 * request — when present, upstream cascades into create-or-update of the
 * underlying catalog product row.
 */
final class ProductMetadata
{
    /**
     * @param array<string, mixed>|null $extra
     */
    public function __construct(
        public readonly string $displayName,
        public readonly ?string $sku = null,
        public readonly ?string $description = null,
        public readonly ?array $extra = null,
    ) {
        if ($displayName === '' || strlen($displayName) > 500) {
            throw new \InvalidArgumentException('display_name must be 1..500 characters');
        }
        if ($sku !== null && strlen($sku) > 100) {
            throw new \InvalidArgumentException('sku must be ≤ 100 characters');
        }
        if ($description !== null && strlen($description) > 5000) {
            throw new \InvalidArgumentException('description must be ≤ 5000 characters');
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
}
