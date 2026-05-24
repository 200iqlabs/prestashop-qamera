<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Dto;

final class RegisterPackshotRequest
{
    /**
     * @param string[] $sourceImageIds
     */
    public function __construct(
        public readonly string $productRef,
        public readonly array $sourceImageIds,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'product_ref' => $this->productRef,
            'source_image_ids' => $this->sourceImageIds,
        ];
    }
}
