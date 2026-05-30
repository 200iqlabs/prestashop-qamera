<?php

declare(strict_types=1);

namespace QameraAi\Module\Packshot\Output;

/**
 * One row of `ps_qamera_imported_output` (add-packshot-output-downloader, D4).
 * Immutable; records that output `outputIndex` of job `qameraJobId` was
 * imported into the PrestaShop gallery as `idImage` (NULL for outputs that
 * are recorded-but-not-placed, e.g. video/reel — v1 scope boundary).
 *
 * The `(qameraJobId, outputIndex)` pair is the ledger's uniqueness key; it
 * drives dedup (import-once), the partial-retry cursor, and the
 * Qamera-origin marker the sync layer consults to skip re-upload.
 */
final class ImportedOutputRow
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $qameraJobId,
        public readonly int $outputIndex,
        public readonly string $outputType,
        public readonly int $idShop,
        public readonly int $idProduct,
        public readonly ?int $idImage,
        public readonly string $importedAt,
    ) {
    }
}
