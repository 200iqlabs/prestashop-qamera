<?php

declare(strict_types=1);

namespace QameraAi\Module\Packshot\Output;

/**
 * Outcome of an {@see OutputImporter::import()} run.
 *
 * A non-null {@see $reason} means the whole job was rejected before any
 * output was written (gate failure, bad product_ref, API error) — all the
 * arrays are then empty. Otherwise the per-output buckets describe what
 * happened: images written, indexes skipped (already in the ledger),
 * non-image outputs recorded-but-not-placed, and per-output failures (a
 * single failing output never aborts the rest of the set).
 */
final class ImportResult
{
    /**
     * @param list<array{output_index:int, id_image:int}> $imported
     * @param list<int>                                    $skipped
     * @param list<int>                                    $recordedNonImage
     * @param list<array{output_index:int, error:string}>  $failures
     */
    public function __construct(
        public readonly array $imported,
        public readonly array $skipped,
        public readonly array $recordedNonImage,
        public readonly array $failures,
        public readonly ?string $reason,
    ) {
    }

    public static function aborted(string $reason): self
    {
        return new self([], [], [], [], $reason);
    }

    public function isAborted(): bool
    {
        return $this->reason !== null;
    }

    public function hasFailures(): bool
    {
        return $this->failures !== [];
    }
}
