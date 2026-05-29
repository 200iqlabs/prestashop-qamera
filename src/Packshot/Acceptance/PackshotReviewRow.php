<?php

declare(strict_types=1);

namespace QameraAi\Module\Packshot\Acceptance;

/**
 * One row of `ps_qamera_packshot_review` (add-packshot-acceptance-flow, D1).
 * Immutable; carries the local voting state for a stage-1 packshot job.
 *
 * Keyed on `qameraJobId` — the address the operator votes against
 * (`POST /jobs/{id}/accept|reject`). Separate from `ps_qamera_packshot_job`
 * (job lifecycle) so review state stays cleanly decoupled (D1).
 */
final class PackshotReviewRow
{
    public const VOTING_PENDING = 'pending';
    public const VOTING_ACCEPTED = 'accepted';
    public const VOTING_REJECTED = 'rejected';

    public const VOTINGS = [
        self::VOTING_PENDING,
        self::VOTING_ACCEPTED,
        self::VOTING_REJECTED,
    ];

    public function __construct(
        public readonly ?int $id,
        public readonly string $qameraJobId,
        public readonly int $idShop,
        public readonly int $idProduct,
        public readonly string $productRef,
        public readonly ?string $assetUrl,
        public readonly string $voting,
        public readonly ?string $votingAt,
        public readonly string $generatedAt,
    ) {
    }
}
