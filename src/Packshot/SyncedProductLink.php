<?php

declare(strict_types=1);

namespace QameraAi\Module\Packshot;

/**
 * Read-side projection of `ps_qamera_product_link` for the submitter and
 * the BO products grid. `qameraImageId` is `null` for rows that have been
 * registered upstream but have not yet had `POST /images` succeed —
 * those rows are NOT generable and the BO renders them with the action
 * disabled.
 */
final class SyncedProductLink
{
    public function __construct(
        public readonly int $idLink,
        public readonly int $idShop,
        public readonly int $idProduct,
        public readonly ?string $qameraImageId,
        public readonly string $qameraProductRef,
        public readonly string $displayNameSnapshot,
        public readonly ?string $status = null,
        public readonly ?string $lastSyncedAt = null,
    ) {
    }

    public function canGenerate(): bool
    {
        return $this->qameraImageId !== null && $this->qameraImageId !== '';
    }
}
