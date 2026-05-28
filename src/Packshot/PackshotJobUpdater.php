<?php

declare(strict_types=1);

namespace QameraAi\Module\Packshot;

use QameraAi\Module\Webhook\Event\InvalidExternalRefException;
use QameraAi\Module\Webhook\Event\PackshotExternalRefParser;
use QameraAi\Module\Webhook\Event\QameraDbException;
use QameraAi\Module\Webhook\WebhookLogger;

/**
 * Routes a webhook delivery into a `ps_qamera_packshot_job` UPSERT.
 * Owns the payload-status → row-status mapping and the pre-submit race
 * recovery path.
 *
 * Status mapping (from `webhook-handler` delta spec):
 *
 *   job.completed → completed
 *   job.failed    → failed
 *   job.cancelled → cancelled
 *   job.retried   → in_progress
 *
 * Unknown event types are mapped to `pending` with a WARNING log line
 * (per spec: do not throw, do not break the webhook ACK).
 *
 * Pre-submit race: when the webhook arrives before the submitter has
 * persisted the local row, the handler can pass `payloadExternalRef`
 * (the `ps:<shop>:<product>:packshot:<uuid>` echoed back by upstream).
 * We parse that, recover the FK via {@see SyncedProductLinkLookup}, and
 * insert a stub row. If the ref is missing or unparseable, we update the
 * row IF it exists (most cases) and silently no-op IF it doesn't (the
 * row's eventual submitter-side insert will carry the right data).
 */
/**
 * NOT `final` because the existing test pattern (mirrored from
 * {@see \QameraAi\Module\Webhook\Event\PackshotLinkUpdater}) subclasses
 * for fakes — see `tests/Support/FakePackshotJobUpdater.php`.
 */
class PackshotJobUpdater
{
    public const STATUS_MAP = [
        'job.completed' => PackshotJobRow::STATUS_COMPLETED,
        'job.failed' => PackshotJobRow::STATUS_FAILED,
        'job.cancelled' => PackshotJobRow::STATUS_CANCELLED,
        'job.retried' => PackshotJobRow::STATUS_IN_PROGRESS,
    ];

    private const TEXT_CAPACITY_BYTES = 65535;

    public function __construct(
        private readonly PackshotJobRepository $repository,
        private readonly SyncedProductLinkLookup $linkLookup,
        private readonly WebhookLogger $logger,
    ) {
    }

    /**
     * @throws QameraDbException on DB error
     */
    public function upsert(
        string $eventType,
        string $deliveryId,
        string $qameraJobId,
        ?string $outputUrl,
        ?string $outputUrlExpiresAt,
        ?string $lastErrorMessage,
        ?string $payloadExternalRef,
        ?string $payloadOrderId
    ): void {
        $status = self::STATUS_MAP[$eventType] ?? null;
        if ($status === null) {
            $status = PackshotJobRow::STATUS_PENDING;
            $this->logger->warning(
                'unknown_payload_event_type_mapped_to_pending',
                [
                    'delivery_id' => $deliveryId,
                    'event_type' => $eventType,
                    'qamera_job_id' => $qameraJobId,
                ]
            );
        }

        if ($lastErrorMessage !== null && strlen($lastErrorMessage) > self::TEXT_CAPACITY_BYTES) {
            $lastErrorMessage = substr($lastErrorMessage, 0, self::TEXT_CAPACITY_BYTES);
        }

        $now = gmdate('Y-m-d H:i:s');

        // Try the row-exists path first via a cheap probe — that lets us
        // skip the FK lookup entirely on the common UPDATE path.
        $existing = $this->repository->findByJobId($qameraJobId);

        if ($existing !== null) {
            $this->repository->upsertFromWebhook(new PackshotJobWebhookUpdate(
                qameraJobId: $qameraJobId,
                status: $status,
                outputUrl: $outputUrl,
                outputUrlExpiresAt: $outputUrlExpiresAt,
                lastErrorMessage: $lastErrorMessage,
                now: $now,
            ));
            return;
        }

        // Pre-submit race: try to recover the FK from payloadExternalRef.
        // If we can't, log INFO and no-op — the submitter's later insert
        // will pick up this job's history.
        if ($payloadExternalRef === null || $payloadOrderId === null) {
            $this->logger->info(
                'webhook_skipped_no_recoverable_fk',
                [
                    'delivery_id' => $deliveryId,
                    'event_type' => $eventType,
                    'qamera_job_id' => $qameraJobId,
                    'reason' => $payloadExternalRef === null ? 'no_external_ref' : 'no_order_id',
                ]
            );
            return;
        }

        try {
            $parsed = PackshotExternalRefParser::parse($payloadExternalRef);
        } catch (InvalidExternalRefException $e) {
            $this->logger->warning(
                'webhook_malformed_packshot_external_ref',
                [
                    'delivery_id' => $deliveryId,
                    'event_type' => $eventType,
                    'qamera_job_id' => $qameraJobId,
                    'external_ref' => $payloadExternalRef,
                ]
            );
            return;
        }

        $idLink = $this->linkLookup->findIdLink($parsed->shopId, $parsed->productId);
        if ($idLink === null) {
            $this->logger->warning(
                'webhook_no_product_link_for_packshot_ref',
                [
                    'delivery_id' => $deliveryId,
                    'event_type' => $eventType,
                    'qamera_job_id' => $qameraJobId,
                    'id_shop' => $parsed->shopId,
                    'id_product' => $parsed->productId,
                ]
            );
            return;
        }

        // Stub insert with the minimum viable column set. ai_model /
        // aspect_ratio / images_count are unknown from the payload — fill
        // with sentinel values that the operator can recognise as
        // pre-submit-race recoveries. session_config_json is empty.
        $this->repository->upsertFromWebhook(new PackshotJobWebhookUpdate(
            qameraJobId: $qameraJobId,
            status: $status,
            outputUrl: $outputUrl,
            outputUrlExpiresAt: $outputUrlExpiresAt,
            lastErrorMessage: $lastErrorMessage,
            now: $now,
            fallbackQameraOrderId: $payloadOrderId,
            fallbackIdQameraProductLink: $idLink,
            fallbackIdShop: $parsed->shopId,
            fallbackIdProduct: $parsed->productId,
            fallbackPackshotExternalRef: $payloadExternalRef,
            fallbackAiModel: '(unknown)',
            fallbackAspectRatio: '1:1',
            fallbackImagesCount: 1,
            fallbackSessionConfig: ['_recovered_via' => 'webhook_pre_submit_race'],
        ));

        $this->logger->info(
            'pre_submit_webhook_upsert',
            [
                'delivery_id' => $deliveryId,
                'event_type' => $eventType,
                'qamera_job_id' => $qameraJobId,
                'id_shop' => $parsed->shopId,
                'id_product' => $parsed->productId,
            ]
        );
    }
}
