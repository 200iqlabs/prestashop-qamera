<?php

declare(strict_types=1);

namespace QameraAi\Module\Packshot;

/**
 * Operator-supplied form fields, normalised + validated before reaching
 * the submitter. The controller is responsible for fanning `productIds`
 * out to `Subject` DTOs (one per id) — this object only carries shared
 * session-level config plus the selected product set.
 */
final class SubmitFormInput
{
    /**
     * @param int[] $productIds   ps_product.id_product ids the operator selected
     */
    public function __construct(
        public readonly int $idShop,
        public readonly array $productIds,
        public readonly string $aiModel,
        public readonly string $aspectRatio,
        public readonly int $imagesCount,
        public readonly ?string $sceneryId = null,
        public readonly ?string $mannequinModelId = null,
        public readonly ?string $presetId = null,
        public readonly ?string $suggestions = null,
    ) {
        if ($productIds === []) {
            throw new \InvalidArgumentException('productIds must contain at least one id');
        }
        foreach ($productIds as $id) {
            if (!is_int($id) || $id < 1) {
                throw new \InvalidArgumentException('productIds must be positive ints');
            }
        }
        if ($aiModel === '') {
            throw new \InvalidArgumentException('aiModel must not be empty');
        }
        if ($imagesCount < 1 || $imagesCount > 50) {
            throw new \InvalidArgumentException('imagesCount must be 1..50');
        }
        if ($suggestions !== null && strlen($suggestions) > 2000) {
            throw new \InvalidArgumentException('suggestions must be <= 2000 characters');
        }
    }
}
