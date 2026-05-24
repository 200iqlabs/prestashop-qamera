<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Dto;

final class SubmitJobRequest
{
    /**
     * @param string[] $sourceImageIds
     */
    public function __construct(
        public readonly string $productRef,
        public readonly string $presetId,
        public readonly string $aspectRatioId,
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
            'preset_id' => $this->presetId,
            'aspect_ratio_id' => $this->aspectRatioId,
            'source_image_ids' => $this->sourceImageIds,
        ];
    }
}
