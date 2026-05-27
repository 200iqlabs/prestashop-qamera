<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Dto;

final class RegisterPackshotRequest
{
    public function __construct(
        public readonly string $externalRef,
        public readonly string $productRef,
        public readonly string $assetId,
        public readonly ?ProductMetadata $productMetadata = null,
        public readonly ?string $sourceImageRef = null,
    ) {
        if ($externalRef === '' || strlen($externalRef) > 200) {
            throw new \InvalidArgumentException('external_ref must be 1..200 characters');
        }
        if ($productRef === '' || strlen($productRef) > 200) {
            throw new \InvalidArgumentException('product_ref must be 1..200 characters');
        }
        if ($assetId === '') {
            throw new \InvalidArgumentException('asset_id must not be empty');
        }
        if ($sourceImageRef !== null && strlen($sourceImageRef) > 200) {
            throw new \InvalidArgumentException('source_image_ref must be ≤ 200 characters');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $payload = [
            'external_ref' => $this->externalRef,
            'product_ref' => $this->productRef,
            'asset_id' => $this->assetId,
        ];
        if ($this->productMetadata !== null) {
            $payload['product_metadata'] = $this->productMetadata->toPayload();
        }
        if ($this->sourceImageRef !== null) {
            $payload['source_image_ref'] = $this->sourceImageRef;
        }

        return $payload;
    }
}
