<?php

declare(strict_types=1);

namespace QameraAi\Module\Packshot;

/**
 * Read-side projection of `ps_qamera_product_link` for the submitter and
 * the BO products grid. `qameraImageId` is `null` for rows that have been
 * registered upstream but have not yet had `POST /images` succeed —
 * those rows are NOT generable and the BO renders them with the action
 * disabled.
 *
 * Phase 4.4 (add-analysis-status-surfacing) added the analysis-status
 * aggregate cache: `analysisStatus` mirrors the upstream Gemini
 * lifecycle reduced across the product's `images[]`; NULL means "never
 * refreshed" (legacy row pre-migration) and is treated as `pending` by
 * `canGenerate()`. The Generate gate requires *both* a registered image
 * AND a `described` analysis — the operator can no longer click Generate
 * on a row whose backend analysis is in flight (which would stall the
 * worker with `PREPARE_PHOTOS_TIMEOUT`).
 */
final class SyncedProductLink
{
    public const ANALYSIS_STATUS_PENDING = 'pending';
    public const ANALYSIS_STATUS_PROCESSING = 'processing';
    public const ANALYSIS_STATUS_DESCRIBED = 'described';
    public const ANALYSIS_STATUS_ERROR = 'error';
    public const ANALYSIS_STATUS_PARTIAL = 'partial';

    public function __construct(
        public readonly int $idLink,
        public readonly int $idShop,
        public readonly int $idProduct,
        public readonly ?string $qameraImageId,
        public readonly string $qameraProductRef,
        public readonly string $displayNameSnapshot,
        public readonly ?string $status = null,
        public readonly ?string $lastSyncedAt = null,
        public readonly ?string $analysisStatus = null,
        public readonly ?int $analysisDescribedCount = null,
        public readonly ?int $analysisTotalCount = null,
        public readonly ?string $analysisRefreshedAt = null,
    ) {
    }

    public function canGenerate(): bool
    {
        return $this->qameraImageId !== null
            && $this->qameraImageId !== ''
            && $this->analysisStatus === self::ANALYSIS_STATUS_DESCRIBED;
    }

    /**
     * Operator-facing hint string for the disabled Generate button. Returns
     * null when the button is enabled. Translation domain is
     * `Modules.Qameraai.Admin`; the controller is responsible for piping
     * the key returned here through the translator.
     *
     * The "sync first" reason takes precedence over "analysis pending":
     * without an image_id there's literally nothing to analyse, so showing
     * the analysis hint would be misleading.
     *
     * Note on `partial`: the spec gates Generate strictly on `described`
     * — the multi-image future will revisit this when the operator
     * actually has a per-image picker. For now, a `partial` row is
     * blocked with the same "still analysing" hint as `processing`.
     */
    public function getDisabledHint(): ?string
    {
        if ($this->qameraImageId === null || $this->qameraImageId === '') {
            return 'Sync this product first';
        }

        return match ($this->analysisStatus) {
            self::ANALYSIS_STATUS_DESCRIBED => null,
            self::ANALYSIS_STATUS_PROCESSING => 'Image is being analysed…',
            self::ANALYSIS_STATUS_PARTIAL => 'Some images still analysing — refresh',
            self::ANALYSIS_STATUS_ERROR => 'Image analysis failed — re-sync product',
            self::ANALYSIS_STATUS_PENDING => 'Waiting for image analysis…',
            null => 'Awaiting analysis status — refresh',
            default => 'Awaiting analysis status — refresh',
        };
    }
}
