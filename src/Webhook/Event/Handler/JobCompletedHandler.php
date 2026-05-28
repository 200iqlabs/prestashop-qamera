<?php

declare(strict_types=1);

namespace QameraAi\Module\Webhook\Event\Handler;

use QameraAi\Module\Webhook\Event\EventHandlerInterface;
use QameraAi\Module\Webhook\Event\ExternalRef;
use QameraAi\Module\Webhook\Event\ExternalRefParser;
use QameraAi\Module\Webhook\Event\InvalidExternalRefException;
use QameraAi\Module\Webhook\Event\PackshotLinkUpdater;
use QameraAi\Module\Webhook\Event\ProductLinkHeartbeat;
use QameraAi\Module\Webhook\Event\WebhookEvent;
use QameraAi\Module\Webhook\WebhookLogger;

/**
 * Handles `job.completed` deliveries.
 *
 * Side effects:
 *   1. Refreshes `ps_qamera_product_link.last_synced_at` for the parsed
 *      `(id_shop, id_product)` (heartbeat — never touches `status`).
 *   2. UPSERTs a `ps_qamera_packshot_link` row keyed on
 *      `qamera_packshot_id` with `status='ready'`,
 *      `last_error_message=NULL`.
 *
 * Defensive guards (all terminate quietly with a log line — `200` ACK):
 *   - missing `external_ref` or `packshot_id`         → ERROR
 *   - malformed `external_ref`                        → WARNING
 *   - product not registered for this shop            → WARNING
 *
 * DB errors throw `QameraDbException`, which the dispatcher catches.
 */
final class JobCompletedHandler implements EventHandlerInterface
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
            'qamera_packshot_ref' => self::packshotRef($externalRef, $packshotId),
            'qamera_job_id' => PayloadExtractor::nullableString($event->payload, 'job_id'),
            'id_shop' => $externalRef->shopId,
            'id_product' => $externalRef->productId,
            'status' => 'ready',
            'last_error_message' => null,
            'now' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public static function packshotRef(ExternalRef $ref, string $packshotId): string
    {
        // Deterministic, <=200 chars: shopId+productId are tiny ints,
        // packshot_id is at most CHAR(36) UUID. Total well under cap.
        return sprintf('ps:%d:%d:packshot:%s', $ref->shopId, $ref->productId, $packshotId);
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
