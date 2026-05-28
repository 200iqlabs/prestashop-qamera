<?php

declare(strict_types=1);

namespace QameraAi\Module\Webhook\Event\Handler;

use QameraAi\Module\Webhook\Event\EventHandlerInterface;
use QameraAi\Module\Webhook\Event\ExternalRefParser;
use QameraAi\Module\Webhook\Event\InvalidExternalRefException;
use QameraAi\Module\Webhook\Event\PackshotLinkUpdater;
use QameraAi\Module\Webhook\Event\ProductLinkHeartbeat;
use QameraAi\Module\Webhook\Event\WebhookEvent;
use QameraAi\Module\Webhook\WebhookLogger;

/**
 * Handles `job.cancelled` deliveries. UPSERTs the packshot row with
 * `status='cancelled'`, clears any prior `last_error_message` (cancel
 * is an authoritative new state — a previous failure no longer applies),
 * and bumps the product heartbeat.
 */
final class JobCancelledHandler implements EventHandlerInterface
{
    public function __construct(
        private readonly PackshotLinkUpdater $packshotUpdater,
        private readonly ProductLinkHeartbeat $productHeartbeat,
        private readonly WebhookLogger $logger
    ) {
    }

    public function handle(WebhookEvent $event): void
    {
        $externalRefRaw = PayloadExtractor::string($event->payload, 'external_ref');
        if ($externalRefRaw === null) {
            $this->logMissing($event, 'external_ref');
            return;
        }

        try {
            $externalRef = ExternalRefParser::parse($externalRefRaw);
        } catch (InvalidExternalRefException $e) {
            $this->logger->warning('malformed_external_ref', [
                'delivery_id' => $event->deliveryId,
                'event_type' => $event->eventType,
                'external_ref' => $externalRefRaw,
            ]);
            return;
        }

        $packshotId = PayloadExtractor::string($event->payload, 'packshot_id');
        if ($packshotId === null) {
            $this->logMissing($event, 'packshot_id');
            return;
        }

        if (!$this->productHeartbeat->touch($externalRef->shopId, $externalRef->productId)) {
            $this->logger->warning('unknown_product_link', [
                'delivery_id' => $event->deliveryId,
                'event_type' => $event->eventType,
                'external_ref' => $externalRefRaw,
            ]);
            return;
        }

        $this->packshotUpdater->upsertByPackshotId([
            'qamera_packshot_id' => $packshotId,
            'qamera_packshot_ref' => JobCompletedHandler::packshotRef($externalRef, $packshotId),
            'qamera_job_id' => PayloadExtractor::nullableString($event->payload, 'job_id'),
            'id_shop' => $externalRef->shopId,
            'id_product' => $externalRef->productId,
            'status' => 'cancelled',
            'last_error_message' => null,
            'now' => gmdate('Y-m-d H:i:s'),
        ]);
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
