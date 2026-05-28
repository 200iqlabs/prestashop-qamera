<?php

declare(strict_types=1);

namespace QameraAi\Module\Packshot;

/**
 * One row of `ps_qamera_packshot_job`. Immutable; carries the full column
 * set in PHP shape (ENUM as string, JSON column as decoded array).
 */
final class PackshotJobRow
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_IN_PROGRESS,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    /**
     * @param array<string, mixed> $sessionConfig
     */
    public function __construct(
        public readonly ?int $id,
        public readonly string $qameraJobId,
        public readonly string $qameraOrderId,
        public readonly int $idQameraProductLink,
        public readonly int $idShop,
        public readonly int $idProduct,
        public readonly string $packshotExternalRef,
        public readonly string $status,
        public readonly ?string $outputUrl,
        public readonly ?string $outputUrlExpiresAt,
        public readonly ?string $lastErrorMessage,
        public readonly string $aiModel,
        public readonly string $aspectRatio,
        public readonly int $imagesCount,
        public readonly array $sessionConfig,
        public readonly string $submittedAt,
        public readonly ?string $lastSyncedAt,
    ) {
    }
}
