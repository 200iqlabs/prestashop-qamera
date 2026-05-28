<?php

declare(strict_types=1);

namespace QameraAi\Module\Webhook\Event\Handler;

use Db;
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

        // Heartbeat always runs (even if the packshot row doesn't exist
        // yet) — the goal is to record that the upstream is still alive.
        // Unknown-product log line follows the same pattern as the other
        // handlers, but unlike them we still attempt the packshot timestamp
        // bump because a row may exist from a prior delivery on a different
        // product mapping (defensive — usually a no-op).
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
