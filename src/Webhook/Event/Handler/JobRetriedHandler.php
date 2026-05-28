<?php

declare(strict_types=1);

namespace QameraAi\Module\Webhook\Event\Handler;

use Db;
use QameraAi\Module\Packshot\PackshotJobUpdater;
use QameraAi\Module\Webhook\Event\EventHandlerInterface;
use QameraAi\Module\Webhook\Event\ExternalRefParser;
use QameraAi\Module\Webhook\Event\InvalidExternalRefException;
use QameraAi\Module\Webhook\Event\ProductLinkHeartbeat;
use QameraAi\Module\Webhook\Event\QameraDbException;
use QameraAi\Module\Webhook\Event\WebhookEvent;
use QameraAi\Module\Webhook\WebhookLogger;

/**
 * Handles `job.retried` deliveries. Bumps `ps_qamera_packshot_link.
 * last_synced_at` for the matching row but NEVER changes `status` —
 * upstream is still working, the terminal `job.completed/failed/
 * cancelled` will arrive later. If no packshot row exists yet, this is
 * a no-op (the eventual terminal event will create it). The product
 * heartbeat is refreshed in either case so the BO sees "upstream still
 * alive" even before the terminal event lands.
 */
final class JobRetriedHandler implements EventHandlerInterface
{
    public function __construct(
        private readonly Db $db,
        private readonly string $tablePrefix,
        private readonly ProductLinkHeartbeat $productHeartbeat,
        private readonly WebhookLogger $logger,
        private readonly PackshotJobUpdater $packshotJobUpdater
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

        // Same unknown-product guard as the other handlers: if no
        // ps_qamera_product_link row exists for (shopId, productId), this
        // delivery is for a product this shop doesn't own — log WARNING
        // and skip the packshot UPDATE entirely. Matches D9's defensive
        // guard for shared installation_id across multiple PS instances.
        if (!$this->productHeartbeat->touch($externalRef->shopId, $externalRef->productId)) {
            $this->logger->warning('unknown_product_link', [
                'delivery_id' => $event->deliveryId,
                'event_type' => $event->eventType,
                'external_ref' => $externalRefRaw,
            ]);
            return;
        }

        // Touch the packshot row IF it exists. UPDATE with no row match
        // is silent (affected_rows == 0) which is the documented no-op.
        $now = gmdate('Y-m-d H:i:s');
        $sql = sprintf(
            'UPDATE `%sqamera_packshot_link` '
            . "SET `last_synced_at` = '%s', `updated_at` = '%s' "
            . "WHERE `qamera_packshot_id` = '%s';",
            $this->tablePrefix,
            $this->db->escape($now, true, true),
            $this->db->escape($now, true, true),
            $this->db->escape($packshotId, true, true)
        );

        if (!$this->db->execute($sql)) {
            throw new QameraDbException('packshot_link retry-touch failed');
        }

        // Phase 4.3 — mirror into ps_qamera_packshot_job (per-job grid).
        // Retried means upstream resumed the job: status flips to
        // in_progress per the PackshotJobUpdater status map. No
        // output_url / error_message yet — those land on the terminal
        // event.
        $jobId = PayloadExtractor::string($event->payload, 'job_id');
        if ($jobId !== null) {
            $this->packshotJobUpdater->upsert(
                eventType: $event->eventType,
                deliveryId: $event->deliveryId,
                qameraJobId: $jobId,
                outputUrl: null,
                outputUrlExpiresAt: null,
                lastErrorMessage: null,
                payloadExternalRef: PayloadExtractor::nullableString($event->payload, 'packshot_external_ref'),
                payloadOrderId: PayloadExtractor::nullableString($event->payload, 'order_id'),
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
