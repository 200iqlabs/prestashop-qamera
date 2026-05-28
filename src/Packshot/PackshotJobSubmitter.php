<?php

declare(strict_types=1);

namespace QameraAi\Module\Packshot;

use QameraAi\Module\Api\Dto\SessionConfig;
use QameraAi\Module\Api\Dto\Subject;
use QameraAi\Module\Api\Dto\SubmitJobRequest;
use QameraAi\Module\Api\Dto\SubmitJobResponse;
use QameraAi\Module\Api\Exception\ApiException;
use QameraAi\Module\Api\QameraApiClient;
use QameraAi\Module\Sync\PrestaShopLoggerWrapper;
use QameraAi\Module\Webhook\Event\QameraDbException;
use Ramsey\Uuid\Uuid;

/**
 * Turn a submitted BO form into one-or-more `POST /jobs` calls + the
 * matching `ps_qamera_packshot_job` rows.
 *
 * Order of operations per chunk:
 *
 *   1. Generate UUID v4 per subject → `Subject.packshotExternalRef`
 *   2. Build SubmitJobRequest with `autoRegisterPackshot=true`
 *   3. Call `QameraApiClient::submitJob()` (Guzzle stack adds Idempotency-Key)
 *   4. On 2xx: map response → PackshotJobRow[] → repository.insertBatch
 *   5. On failure: catch typed ApiException, record per-chunk failure,
 *      DO NOT write any local rows for that chunk
 *
 * Chunks are processed sequentially (NOT parallel) so a partial failure
 * mid-batch surfaces predictable state — earlier chunks committed,
 * failed chunk and later chunks reported back to the controller.
 */
final class PackshotJobSubmitter
{
    public const MAX_SUBJECTS_PER_SESSION = 100;

    public function __construct(
        private readonly QameraApiClient $apiClient,
        private readonly PackshotJobRepository $repository,
        private readonly SyncedProductLinkLookup $linkLookup,
        private readonly PrestaShopLoggerWrapper $logger,
    ) {
    }

    public function submit(SubmitFormInput $input): SubmitResult
    {
        // Dedupe id_product up front: a manipulated/stale POST could carry
        // the same product twice, which would later overwrite earlier
        // entries in $refIndex (keyed by qamera_product_ref) inside
        // submitChunk() and decouple the response-to-row mapping from the
        // outbound Subjects. We accept the first occurrence and drop
        // duplicates silently — the operator-facing skipped counter is
        // reserved for "not eligible", not "you double-clicked".
        $productIds = array_values(array_unique($input->productIds));

        // SyncedProductLinkLookup::loadByProductIds throws QameraDbException
        // on DB failure; surface that as a structured SubmitResult so the
        // BO controller flashes a coherent error rather than rendering a
        // 500 (the chunk-level paths already handle DB failure this way).
        try {
            $links = $this->linkLookup->loadByProductIds($input->idShop, $productIds);
        } catch (QameraDbException $e) {
            $this->logEvent(
                3,
                'submit_lookup_db_failed',
                [
                    'id_shop' => $input->idShop,
                    'message' => $e->getMessage(),
                ]
            );
            return new SubmitResult(
                0,
                1,
                0,
                [],
                [1 => 'Lookup failed: ' . $e->getMessage()],
            );
        }

        $generable = [];
        $skipped = 0;
        foreach ($productIds as $idProduct) {
            $link = $links[$idProduct] ?? null;
            if ($link === null || !$link->canGenerate()) {
                $skipped++;
                continue;
            }
            $generable[] = $link;
        }

        if ($generable === []) {
            // Caller has already surfaced the disabled state via the
            // grid; this defensive path catches a stale form post (the
            // operator started the form before a row's qamera_image_id
            // was nulled out, then submitted). Return a zero-result so
            // the controller can flash "no eligible products".
            $this->logEvent(
                1,
                'submit_no_eligible_products',
                [
                    'id_shop' => $input->idShop,
                    'skipped' => $skipped,
                ]
            );
            return new SubmitResult(0, 0, 0, [], []);
        }

        $chunks = array_chunk($generable, self::MAX_SUBJECTS_PER_SESSION);

        $sessionsSubmitted = 0;
        $sessionsFailed = 0;
        $jobsPersisted = 0;
        $orderIds = [];
        $chunkFailures = [];

        foreach ($chunks as $index => $chunk) {
            $chunkNumber = $index + 1; // 1-based for human-facing messages
            try {
                $persisted = $this->submitChunk($input, $chunk);
                $sessionsSubmitted++;
                $jobsPersisted += $persisted['jobs_persisted'];
                $orderIds[] = $persisted['order_id'];
            } catch (ApiException $e) {
                $sessionsFailed++;
                $chunkFailures[$chunkNumber] = $e->getMessage();
                $this->logEvent(
                    3,
                    'submit_chunk_failed',
                    [
                        'chunk' => $chunkNumber,
                        'chunk_size' => count($chunk),
                        'exception' => get_class($e),
                        'message' => $e->getMessage(),
                    ]
                );
            } catch (QameraDbException $e) {
                // API call succeeded but local insert failed. The webhook
                // upsert path will eventually create the row when the
                // terminal event lands — log loudly so the operator can
                // reconcile if the webhook never arrives.
                $sessionsFailed++;
                $chunkFailures[$chunkNumber] = 'Local persistence failed: ' . $e->getMessage();
                $this->logEvent(
                    3,
                    'submit_chunk_db_failed_after_api_success',
                    [
                        'chunk' => $chunkNumber,
                        'chunk_size' => count($chunk),
                        'message' => $e->getMessage(),
                    ]
                );
            }
        }

        return new SubmitResult(
            $sessionsSubmitted,
            $sessionsFailed,
            $jobsPersisted,
            $orderIds,
            $chunkFailures,
        );
    }

    /**
     * @param SyncedProductLink[] $chunk
     *
     * @return array{order_id: string, jobs_persisted: int}
     *
     * @throws ApiException        on upstream failure
     * @throws QameraDbException   on local persistence failure
     */
    private function submitChunk(SubmitFormInput $input, array $chunk): array
    {
        $subjects = [];
        /** @var array<string, array{link: SyncedProductLink, packshot_external_ref: string}> $refIndex */
        $refIndex = [];

        foreach ($chunk as $link) {
            $uuid = Uuid::uuid4()->toString();
            $packshotRef = sprintf('ps:%d:%d:packshot:%s', $link->idShop, $link->idProduct, $uuid);
            $refIndex[$link->qameraProductRef] = [
                'link' => $link,
                'packshot_external_ref' => $packshotRef,
            ];

            $subjects[] = new Subject(
                packshotAssetId: (string) $link->qameraImageId,
                productLabel: $this->truncateLabel($link->displayNameSnapshot),
                productRef: $link->qameraProductRef,
                imagesCount: $input->imagesCount,
                aiModel: $input->aiModel,
                productName: $link->displayNameSnapshot !== '' ? $link->displayNameSnapshot : null,
                autoRegisterPackshot: true,
                packshotExternalRef: $packshotRef,
            );
        }

        $sessionConfig = new SessionConfig(
            aspectRatio: $input->aspectRatio,
            modelId: $input->mannequinModelId,
            sceneryId: $input->sceneryId,
            presetId: $input->presetId,
            suggestions: $input->suggestions,
        );

        $request = new SubmitJobRequest($sessionConfig, $subjects);
        $response = $this->apiClient->submitJob($request);

        $rows = $this->mapResponseToRows($input, $response, $refIndex);
        $this->repository->insertBatch($rows);

        return [
            'order_id' => $response->orderId,
            'jobs_persisted' => count($rows),
        ];
    }

    /**
     * @param array<string, array{link: SyncedProductLink, packshot_external_ref: string}> $refIndex
     *
     * @return PackshotJobRow[]
     */
    private function mapResponseToRows(
        SubmitFormInput $input,
        SubmitJobResponse $response,
        array $refIndex,
    ): array {
        $sessionConfigSnapshot = [
            'ai_model' => $input->aiModel,
            'aspect_ratio' => $input->aspectRatio,
            'images_count' => $input->imagesCount,
            'scenery_id' => $input->sceneryId,
            'mannequin_model_id' => $input->mannequinModelId,
            'preset_id' => $input->presetId,
            'suggestions' => $input->suggestions,
        ];

        $rows = [];
        foreach ($response->subjects as $respSubject) {
            $entry = $refIndex[$respSubject->productRef] ?? null;
            if ($entry === null) {
                // Upstream returned a subject we never sent — log + skip;
                // do NOT throw (other rows in this batch should still
                // persist). Contract violation worth a WARNING, not fatal.
                $this->logEvent(
                    3,
                    'submit_response_unknown_subject',
                    [
                        'order_id' => $response->orderId,
                        'product_ref' => $respSubject->productRef,
                    ]
                );
                continue;
            }

            $link = $entry['link'];
            $packshotRef = $entry['packshot_external_ref'];

            foreach ($respSubject->jobIds as $jobId) {
                // All job_ids from the same Subject share one packshot_
                // external_ref (auto_register_packshot creates one packshot
                // per Subject regardless of imagesCount). The schema's
                // index on packshot_external_ref is intentionally
                // non-unique — see Installer comment.
                $rows[] = new PackshotJobRow(
                    id: null,
                    qameraJobId: $jobId,
                    qameraOrderId: $response->orderId,
                    idQameraProductLink: $link->idLink,
                    idShop: $link->idShop,
                    idProduct: $link->idProduct,
                    packshotExternalRef: $packshotRef,
                    status: PackshotJobRow::STATUS_PENDING,
                    outputUrl: null,
                    outputUrlExpiresAt: null,
                    lastErrorMessage: null,
                    aiModel: $input->aiModel,
                    aspectRatio: $input->aspectRatio,
                    imagesCount: $input->imagesCount,
                    sessionConfig: $sessionConfigSnapshot,
                    submittedAt: gmdate('Y-m-d H:i:s'),
                    lastSyncedAt: null,
                );
            }
        }

        return $rows;
    }

    /**
     * @param array<string, string|int|null> $context
     */
    private function logEvent(int $severity, string $message, array $context): void
    {
        $line = '[QameraAi][packshot-submit] ' . $message;
        if ($context !== []) {
            $pairs = [];
            foreach ($context as $key => $value) {
                $pairs[] = $key . '=' . ($value === null ? '-' : (string) $value);
            }
            $line .= ' ' . implode(' ', $pairs);
        }
        $this->logger->addLog($line, $severity, null, 'QameraAiModule', null, true);
    }

    private function truncateLabel(string $label): string
    {
        if ($label === '') {
            return '(unnamed product)';
        }
        // Subject's 200 limit is character-bounded; strlen/substr would
        // both miscount and slice mid-sequence for non-ASCII names. Mirror
        // the mb_substr-with-fallback pattern from src/Sync/*.
        if (function_exists('mb_strlen') && mb_strlen($label) > 200) {
            return mb_substr($label, 0, 200);
        }
        if (!function_exists('mb_strlen') && strlen($label) > 200) {
            return substr($label, 0, 200);
        }
        return $label;
    }
}
