<?php

declare(strict_types=1);

namespace QameraAi\Module\Packshot;

use QameraAi\Module\Webhook\Event\InvalidProductRefException;
use QameraAi\Module\Webhook\Event\ProductRefParser;
use QameraAi\Module\Webhook\Event\QameraDbException;
use QameraAi\Module\Webhook\WebhookLogger;

/**
 * Routes a webhook delivery into a `ps_qamera_packshot_job` UPSERT, keyed
 * on `qamera_job_id` (= the wire body's `job.id`). Owns the event-type →
 * row-status mapping and the pre-submit race recovery path.
 *
 * Status mapping (from `webhook-handler` spec):
 *
 *   job.completed → completed
 *   job.failed    → failed
 *   job.cancelled → cancelled
 *   job.retried   → in_progress
 *
 * Unknown event types are mapped to `pending` with a WARNING log line
 * (do not throw, do not break the webhook ACK).
 *
 * Pre-submit race: when the webhook arrives before the submitter has
 * persisted the local row, we recover the FK from the payload's
 * `job.product_ref` (`ps:<shop>:<product>`) via {@see ProductRefParser}
 * and {@see SyncedProductLinkLookup}, then insert a stub row. If the ref
 * or order id is missing/unparseable, we update the row IF it exists and
 * silently no-op IF it doesn't (the submitter's later insert carries the
 * right data).
 */
/**
 * NOT `final` because the existing test pattern subclasses for fakes —
 * see `tests/Support/FakePackshotJobUpdater.php`.
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
        ?string $productRef,
        ?string $orderId
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
            $lastErrorMessage = function_exists('mb_strcut')
                ? mb_strcut($lastErrorMessage, 0, self::TEXT_CAPACITY_BYTES, 'UTF-8')
                : substr($lastErrorMessage, 0, self::TEXT_CAPACITY_BYTES);
        }

        $now = gmdate('Y-m-d H:i:s');

        // Common path: the submitter already persisted the row — UPDATE in place.
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

        // Pre-submit race: recover the FK from job.product_ref.
        if ($productRef === null || $orderId === null) {
            $this->logger->info(
                'webhook_skipped_no_recoverable_fk',
                [
                    'delivery_id' => $deliveryId,
                    'event_type' => $eventType,
                    'qamera_job_id' => $qameraJobId,
                    'reason' => $productRef === null ? 'no_product_ref' : 'no_order_id',
                ]
            );
            return;
        }

        try {
            $parsed = ProductRefParser::parse($productRef);
        } catch (InvalidProductRefException $e) {
            $this->logger->warning(
                'webhook_malformed_product_ref',
                [
                    'delivery_id' => $deliveryId,
                    'event_type' => $eventType,
                    'qamera_job_id' => $qameraJobId,
                    'product_ref' => $productRef,
                ]
            );
            return;
        }

        $idLink = $this->linkLookup->findIdLink($parsed->shopId, $parsed->productId);
        if ($idLink === null) {
            $this->logger->warning(
                'webhook_no_product_link_for_product_ref',
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

        // Stub insert with the minimum viable column set. The
        // packshot_external_ref column is UNIQUE NOT NULL, so synthesise a
        // deterministic value from the (globally unique) job id. ai_model /
        // aspect_ratio / images_count are unknown from the webhook payload —
        // fill with sentinels the operator can recognise as race recoveries.
        $this->repository->upsertFromWebhook(new PackshotJobWebhookUpdate(
            qameraJobId: $qameraJobId,
            status: $status,
            outputUrl: $outputUrl,
            outputUrlExpiresAt: $outputUrlExpiresAt,
            lastErrorMessage: $lastErrorMessage,
            now: $now,
            fallbackQameraOrderId: $orderId,
            fallbackIdQameraProductLink: $idLink,
            fallbackIdShop: $parsed->shopId,
            fallbackIdProduct: $parsed->productId,
            fallbackPackshotExternalRef: $productRef . ':packshot:job-' . $qameraJobId,
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
