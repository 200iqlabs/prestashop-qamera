<?php

declare(strict_types=1);

namespace QameraAi\Module\Webhook\Event\Handler;

use QameraAi\Module\Packshot\Acceptance\PackshotReviewWriter;
use QameraAi\Module\Packshot\PackshotJobUpdater;
use QameraAi\Module\Webhook\Event\EventHandlerInterface;
use QameraAi\Module\Webhook\Event\InvalidProductRefException;
use QameraAi\Module\Webhook\Event\ProductLinkHeartbeat;
use QameraAi\Module\Webhook\Event\ProductRefParser;
use QameraAi\Module\Webhook\Event\WebhookEvent;
use QameraAi\Module\Webhook\WebhookLogger;

/**
 * Handles `job.completed` deliveries.
 *
 * Reads the real wire body: `payload.job.product_ref` (`ps:shop:product`),
 * `payload.job.id`, `payload.outputs[0].url`. Side effects:
 *   1. Refreshes `ps_qamera_product_link.last_synced_at` for the parsed
 *      `(id_shop, id_product)` (heartbeat — never touches `status`).
 *   2. UPSERTs the `ps_qamera_packshot_job` mirror keyed on `qamera_job_id`
 *      with `status='completed'` and the output URL.
 *   3. When `job.job_type === 'packshot'` (add-packshot-acceptance-flow):
 *      UPSERTs a pending `ps_qamera_packshot_review` row (asset_url =
 *      `outputs[0].url`). A `photo_shoot` completion takes only steps 1–2.
 *      Branches on the wire body's `job.job_type`; no new event type.
 *
 * Defensive guards (all terminate quietly with a log line — `200` ACK):
 *   - missing `job.product_ref` or `job.id`           → ERROR
 *   - malformed `job.product_ref`                      → WARNING
 *   - product not registered for this shop             → WARNING
 *
 * DB errors throw `QameraDbException`, which the dispatcher catches.
 */
final class JobCompletedHandler implements EventHandlerInterface
{
    public function __construct(
        private readonly ProductLinkHeartbeat $productHeartbeat,
        private readonly WebhookLogger $logger,
        private readonly PackshotJobUpdater $packshotJobUpdater,
        private readonly PackshotReviewWriter $reviewWriter
    ) {
    }

    public function handle(WebhookEvent $event): void
    {
        $productRefRaw = PayloadExtractor::jobString($event->payload, 'product_ref');
        if ($productRefRaw === null) {
            $this->logMissing($event, 'job.product_ref');
            return;
        }

        try {
            $productRef = ProductRefParser::parse($productRefRaw);
        } catch (InvalidProductRefException $e) {
            $this->logger->warning('malformed_product_ref', [
                'delivery_id' => $event->deliveryId,
                'event_type' => $event->eventType,
                'product_ref' => $productRefRaw,
            ]);
            return;
        }

        $jobId = PayloadExtractor::jobString($event->payload, 'id');
        if ($jobId === null) {
            $this->logMissing($event, 'job.id');
            return;
        }

        if (!$this->productHeartbeat->touch($productRef->shopId, $productRef->productId)) {
            $this->logger->warning('unknown_product_link', [
                'delivery_id' => $event->deliveryId,
                'event_type' => $event->eventType,
                'product_ref' => $productRefRaw,
            ]);
            return;
        }

        $outputUrl = PayloadExtractor::firstOutputUrl($event->payload);

        $this->packshotJobUpdater->upsert(
            eventType: $event->eventType,
            deliveryId: $event->deliveryId,
            qameraJobId: $jobId,
            outputUrl: $outputUrl,
            outputUrlExpiresAt: null,
            lastErrorMessage: null,
            productRef: $productRefRaw,
            orderId: PayloadExtractor::jobString($event->payload, 'order_id'),
        );

        // Stage-1 packshot completion → pending review queue. The branch is on
        // the wire body's `job.job_type` (no new event type — upstream D4); a
        // `photo_shoot` (or untyped legacy) completion takes only the mirror
        // path above and never enters the review queue.
        if (PayloadExtractor::jobString($event->payload, 'job_type') === 'packshot') {
            $this->reviewWriter->recordPending(
                deliveryId: $event->deliveryId,
                qameraJobId: $jobId,
                idShop: $productRef->shopId,
                idProduct: $productRef->productId,
                assetUrl: $outputUrl,
            );
        }
    }

    private function logMissing(WebhookEvent $event, string $field): void
    {
        $this->logger->error('payload_missing_field', [
            'delivery_id' => $event->deliveryId,
            'event_type' => $event->eventType,
            'field' => $field,
        ]);
    }
}
