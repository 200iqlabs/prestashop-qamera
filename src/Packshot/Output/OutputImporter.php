<?php

declare(strict_types=1);

namespace QameraAi\Module\Packshot\Output;

use QameraAi\Module\Api\Exception\ApiException;
use QameraAi\Module\Api\QameraApiClient;
use QameraAi\Module\Packshot\Acceptance\PackshotReviewRepository;
use QameraAi\Module\Packshot\Acceptance\PackshotReviewRow;
use QameraAi\Module\Packshot\SyncedProductLinkLookup;
use QameraAi\Module\Sync\PrestaShopLoggerWrapper;
use QameraAi\Module\Webhook\Event\InvalidProductRefException;
use QameraAi\Module\Webhook\Event\ProductRefParser;
use Throwable;

/**
 * Imports a completed job's image outputs into the PrestaShop product
 * gallery (add-packshot-output-downloader). Fetches the job fresh via
 * `GET /jobs/{id}` at action time (re-signed URLs — D5), gates per the
 * acceptance flow, writes each image via {@see GalleryImageWriter}, and
 * records every output (image or not) in the {@see ImportedOutputRepository}
 * ledger for dedup / partial-retry / origin-marking.
 *
 * Gate (D-gating): a job is importable when `status='completed'` and, if it
 * is a packshot, it has been accepted. "Is a packshot" is derived from the
 * presence of a `ps_qamera_packshot_review` row — the 4.4 webhook creates one
 * iff `job_type='packshot'`, so no `job_type` column is needed and the 4.4
 * write path is untouched. photo_shoot (and legacy packshots with no review
 * row) pass the gate.
 *
 * Not `final` so the `now()` seam can be frozen in tests.
 */
class OutputImporter
{
    public function __construct(
        private readonly QameraApiClient $api,
        private readonly ImportedOutputRepository $ledger,
        private readonly PackshotReviewRepository $reviews,
        private readonly SyncedProductLinkLookup $links,
        private readonly GalleryImageWriter $gallery,
        private readonly PrestaShopLoggerWrapper $logger,
    ) {
    }

    /**
     * Job-level gate shared by the grid (display) and the import action so
     * the two never disagree. Returns null when the job passes the gate, or
     * a stable reason code (`not_completed` / `packshot_not_accepted`).
     */
    public function jobGateReason(string $status, ?PackshotReviewRow $review): ?string
    {
        if ($status !== 'completed') {
            return 'not_completed';
        }
        if ($review !== null && $review->voting !== PackshotReviewRow::VOTING_ACCEPTED) {
            return 'packshot_not_accepted';
        }
        return null;
    }

    /**
     * Per-row display state for the Jobs history grid (no API call — pure
     * over local data the controller already loaded). Uses the same
     * {@see jobGateReason} as the import action so grid and action agree.
     *
     * @param int[] $importedIndexes ledger indexes already imported for the job
     *
     * @return array{state:string, reason?:string}
     *   state ∈ imported | active | disabled | absent
     */
    public function gridState(string $status, ?PackshotReviewRow $review, array $importedIndexes): array
    {
        if ($importedIndexes !== []) {
            return ['state' => 'imported'];
        }
        $reason = $this->jobGateReason($status, $review);
        if ($reason === 'not_completed') {
            return ['state' => 'absent'];
        }
        if ($reason !== null) {
            return ['state' => 'disabled', 'reason' => $reason];
        }
        return ['state' => 'active'];
    }

    public function import(string $jobId): ImportResult
    {
        try {
            $job = $this->api->getJob($jobId);
        } catch (ApiException $e) {
            $this->log(sprintf('output import: getJob failed for %s: %s', $jobId, $e->getMessage()), 0);
            return ImportResult::aborted('api_error');
        }

        $gate = $this->jobGateReason($job->status, $this->reviews->findByJobId($jobId));
        if ($gate !== null) {
            return ImportResult::aborted($gate);
        }

        try {
            $ref = ProductRefParser::parse((string) $job->productRef);
        } catch (InvalidProductRefException $e) {
            return ImportResult::aborted('invalid_product_ref');
        }

        if ($this->links->findIdLink($ref->shopId, $ref->productId) === null) {
            return ImportResult::aborted('product_not_registered');
        }

        $already = $this->ledger->importedIndexes($jobId);
        $now = $this->now();

        $imported = [];
        $skipped = [];
        $nonImage = [];
        $failures = [];

        foreach ($job->outputs as $i => $out) {
            $index = (int) $i;
            if (in_array($index, $already, true)) {
                $skipped[] = $index;
                continue;
            }

            if (!$this->isImageType($out->type)) {
                // v1 scope boundary: record video/reel etc. but place nothing.
                $this->ledger->record(new ImportedOutputRow(
                    null,
                    $jobId,
                    $index,
                    $out->type,
                    $ref->shopId,
                    $ref->productId,
                    null,
                    $now,
                ));
                $nonImage[] = $index;
                continue;
            }

            try {
                $idImage = $this->gallery->importImage($ref->productId, $ref->shopId, $out->url);
                $this->ledger->record(new ImportedOutputRow(
                    null,
                    $jobId,
                    $index,
                    $out->type,
                    $ref->shopId,
                    $ref->productId,
                    $idImage,
                    $now,
                ));
                $imported[] = ['output_index' => $index, 'id_image' => $idImage];
            } catch (Throwable $e) {
                $failures[] = ['output_index' => $index, 'error' => $this->sanitize($e)];
                $this->log(sprintf(
                    'output import: output %d of job %s failed: %s',
                    $index,
                    $jobId,
                    $e->getMessage()
                ), $ref->productId);
            }
        }

        return new ImportResult($imported, $skipped, $nonImage, $failures, null);
    }

    private function isImageType(string $type): bool
    {
        return str_starts_with(strtolower($type), 'image/');
    }

    private function sanitize(Throwable $e): string
    {
        $message = $e->getMessage();
        if (function_exists('mb_substr')) {
            return mb_strlen($message) > 300 ? mb_substr($message, 0, 300) : $message;
        }
        /** @phpstan-ignore-next-line — mb_* fallback */
        return strlen($message) > 300 ? substr($message, 0, 300) : $message;
    }

    private function log(string $message, ?int $idProduct): void
    {
        $this->logger->addLog(
            '[QameraAi] ' . $message,
            2,
            null,
            'QameraAiModule',
            $idProduct !== null && $idProduct > 0 ? $idProduct : null,
            true,
        );
    }

    protected function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
