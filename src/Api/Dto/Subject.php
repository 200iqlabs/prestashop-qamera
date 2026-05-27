<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Dto;

/**
 * One product within a session. The backend expands each subject into
 * `imagesCount` `cg_jobs` rows. `aiModel` is `"provider/model"`.
 *
 * Upstream `SubjectSchema` has 4 more optional fields than design.md §4
 * lists (`productSide`, `productGeneralCategory`, `autoRegisterPackshot`,
 * `packshotExternalRef`); included here for full contract parity.
 */
final class Subject
{
    /**
     * @param array<int, string>|null   $referenceAssetIds
     * @param array<string, mixed>|null $providerSettings
     */
    public function __construct(
        public readonly string $packshotAssetId,
        public readonly string $productLabel,
        public readonly string $productRef,
        public readonly int $imagesCount,
        public readonly string $aiModel,
        public readonly ?array $referenceAssetIds = null,
        public readonly ?array $providerSettings = null,
        public readonly ?string $productName = null,
        public readonly ?string $productSpecificCategory = null,
        public readonly ?string $productSide = null,
        public readonly ?string $productGeneralCategory = null,
        public readonly ?bool $autoRegisterPackshot = null,
        public readonly ?string $packshotExternalRef = null,
    ) {
        if ($productLabel === '' || strlen($productLabel) > 200) {
            throw new \InvalidArgumentException('product_label must be 1..200 characters');
        }
        if ($productRef === '' || strlen($productRef) > 200) {
            throw new \InvalidArgumentException('product_ref must be 1..200 characters');
        }
        if ($imagesCount <= 0 || $imagesCount > 50) {
            throw new \InvalidArgumentException('images_count must be in 1..50');
        }
        if (preg_match('/^[a-z0-9_-]+\/[a-z0-9._-]+$/', $aiModel) !== 1) {
            throw new \InvalidArgumentException('ai_model must be "provider/model"');
        }
        if ($packshotExternalRef !== null && strlen($packshotExternalRef) > 200) {
            throw new \InvalidArgumentException('packshot_external_ref must be ≤ 200 characters');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $payload = [
            'packshot_asset_id' => $this->packshotAssetId,
            'product_label' => $this->productLabel,
            'product_ref' => $this->productRef,
            'images_count' => $this->imagesCount,
            'ai_model' => $this->aiModel,
        ];
        if ($this->referenceAssetIds !== null) {
            $payload['reference_asset_ids'] = $this->referenceAssetIds;
        }
        if ($this->providerSettings !== null) {
            $payload['provider_settings'] = $this->providerSettings;
        }
        if ($this->productName !== null) {
            $payload['product_name'] = $this->productName;
        }
        if ($this->productSpecificCategory !== null) {
            $payload['product_specific_category'] = $this->productSpecificCategory;
        }
        if ($this->productSide !== null) {
            $payload['product_side'] = $this->productSide;
        }
        if ($this->productGeneralCategory !== null) {
            $payload['product_general_category'] = $this->productGeneralCategory;
        }
        if ($this->autoRegisterPackshot !== null) {
            $payload['auto_register_packshot'] = $this->autoRegisterPackshot;
        }
        if ($this->packshotExternalRef !== null) {
            $payload['packshot_external_ref'] = $this->packshotExternalRef;
        }

        return $payload;
    }
}
