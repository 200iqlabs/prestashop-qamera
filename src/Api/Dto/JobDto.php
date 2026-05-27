<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Dto;

use QameraAi\Module\Api\Internal\ArrayOf;

/**
 * Full `JobDto` per upstream `JobDtoSchema`. Replaces the Phase-1
 * `JobResponse` which had a flat `resultUrls: string[]` — callers must
 * iterate `outputs` (array of {@see JobOutput}) and read `.url`.
 *
 * `orderId` is nullable per upstream zod (pre-session orders).
 * `voting` is `'accepted'` | `'rejected'` | null.
 */
final class JobDto
{
    /**
     * @param JobOutput[]               $outputs
     * @param array<string, mixed>|null $externalMetadata
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $orderId,
        public readonly string $status,
        public readonly string $jobType,
        public readonly string $provider,
        public readonly string $model,
        public readonly int $unitCost,
        public readonly int $attemptCount,
        #[ArrayOf(JobOutput::class)]
        public readonly array $outputs,
        public readonly ?ErrorBody $error,
        public readonly ?array $externalMetadata,
        public readonly ?string $packshotAssetId,
        public readonly ?string $productLabel,
        public readonly ?string $productRef,
        public readonly ?string $voting,
        public readonly ?string $votingAt,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly ?string $completedAt,
    ) {
    }
}
