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
 * Handles `job.failed` deliveries. Persists the upstream error message
 * (truncated to TEXT capacity = 65535 bytes) and flips packshot status
 * to `'failed'`. The product link's status / qamera_product_id are NOT
 * modified — a downstream packshot failure does not invalidate the
 * upstream product registration.
 */
final class JobFailedHandler implements EventHandlerInterface
{
    private const TEXT_CAPACITY_BYTES = 65535;

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

        $errorMessage = PayloadExtractor::nullableString($event->payload, 'error_message');
        if ($errorMessage !== null && strlen($errorMessage) > self::TEXT_CAPACITY_BYTES) {
            $errorMessage = substr($errorMessage, 0, self::TEXT_CAPACITY_BYTES);
        }

        $this->packshotUpdater->upsertByPackshotId([
            'qamera_packshot_id' => $packshotId,
            'qamera_packshot_ref' => JobCompletedHandler::packshotRef($externalRef, $packshotId),
            'qamera_job_id' => PayloadExtractor::nullableString($event->payload, 'job_id'),
            'id_shop' => $externalRef->shopId,
            'id_product' => $externalRef->productId,
            'status' => 'failed',
            'last_error_message' => $errorMessage,
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
