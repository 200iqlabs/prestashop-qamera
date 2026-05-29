<?php

declare(strict_types=1);

namespace QameraAi\Module\Webhook\Event\Handler;

use QameraAi\Module\Packshot\PackshotJobUpdater;
use QameraAi\Module\Webhook\Event\EventHandlerInterface;
use QameraAi\Module\Webhook\Event\InvalidProductRefException;
use QameraAi\Module\Webhook\Event\ProductLinkHeartbeat;
use QameraAi\Module\Webhook\Event\ProductRefParser;
use QameraAi\Module\Webhook\Event\WebhookEvent;
use QameraAi\Module\Webhook\WebhookLogger;

/**
 * Handles `job.cancelled` deliveries. Refreshes the product heartbeat and
 * upserts the `ps_qamera_packshot_job` mirror with `status='cancelled'`.
 */
final class JobCancelledHandler implements EventHandlerInterface
{
    public function __construct(
        private readonly ProductLinkHeartbeat $productHeartbeat,
        private readonly WebhookLogger $logger,
        private readonly PackshotJobUpdater $packshotJobUpdater
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

        $this->packshotJobUpdater->upsert(
            eventType: $event->eventType,
            deliveryId: $event->deliveryId,
            qameraJobId: $jobId,
            outputUrl: null,
            outputUrlExpiresAt: null,
            lastErrorMessage: null,
            productRef: $productRefRaw,
            orderId: PayloadExtractor::jobString($event->payload, 'order_id'),
        );
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
