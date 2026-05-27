<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Dto;

/**
 * Plugin-side single-image request. The client wraps it in a `{images:[…]}`
 * bulk envelope when dispatching to upstream `POST /images`.
 *
 * `externalRef` is the caller-supplied stable identifier — upstream keys
 * idempotency on (installation_id, external_ref), so re-registering with
 * the same value returns `status:'existing'` without duplicating rows.
 */
final class RegisterImageRequest
{
    public function __construct(
        public readonly string $externalRef,
        public readonly string $productRef,
        public readonly string $assetId,
        public readonly ?ProductMetadata $productMetadata = null,
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
    }

    /**
     * @return array<string, mixed> shape: `external_ref`, `product_ref`,
     *                               `asset_id`, optional `product_metadata`.
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

        return $payload;
    }
}
