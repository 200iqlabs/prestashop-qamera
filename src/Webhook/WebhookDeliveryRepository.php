<?php

declare(strict_types=1);

namespace QameraAi\Module\Webhook;

use Db;

/**
 * Persists accepted webhook deliveries to `{prefix}qamera_webhook_delivery`.
 *
 * Uses `INSERT … ON DUPLICATE KEY UPDATE delivery_id=delivery_id` against
 * the PK so concurrent inserts with the same `delivery_id` produce exactly
 * one row. The discriminated outcome (`accepted` vs `duplicate`) is derived
 * by reading the row back and comparing the persisted `received_at` to the
 * value this request attempted to insert.
 *
 * DB exceptions surface to the caller (the controller emits 500 per D10).
 */
class WebhookDeliveryRepository
{
    public function __construct(
        private readonly Db $db,
        private readonly string $tablePrefix
    ) {
    }

    public function recordAccepted(
        string $deliveryId,
        string $eventType,
        string $rawPayload,
        int $receivedAtEpoch
    ): string {
        // PrestaShop's Db::getRow() appends `LIMIT 1` itself, so the
        // SQL passed in MUST NOT include one (a double `LIMIT 1 LIMIT 1`
        // is a syntax error). Same applies to the post-insert re-read.
        $existing = $this->db->getRow(sprintf(
            'SELECT `delivery_id` FROM `%sqamera_webhook_delivery` WHERE `delivery_id` = \'%s\'',
            $this->tablePrefix,
            $this->escape($deliveryId)
        ));

        if (is_array($existing) && isset($existing['delivery_id'])) {
            return DeliveryOutcome::DUPLICATE;
        }

        $receivedAt = gmdate('Y-m-d H:i:s', $receivedAtEpoch);
        $sql = sprintf(
            'INSERT INTO `%sqamera_webhook_delivery` '
            . '(`delivery_id`, `received_at`, `event_type`, `status`, `last_error_message`, `raw_payload`) '
            . "VALUES ('%s', '%s', '%s', 'accepted', NULL, '%s') "
            . 'ON DUPLICATE KEY UPDATE `delivery_id` = `delivery_id`;',
            $this->tablePrefix,
            $this->escape($deliveryId),
            $this->escape($receivedAt),
            $this->escape($eventType),
            $this->escape($rawPayload)
        );

        if (!$this->db->execute($sql)) {
            throw new RepositoryException('insert failed');
        }

        // Re-read: if the SELECT-before-INSERT raced with another worker
        // they both saw "no row" and both ran INSERT; ON DUPLICATE KEY
        // UPDATE made one a no-op. The one whose payload didn't land is
        // the duplicate. Compared by raw_payload because received_at can
        // tie on the same epoch second.
        $check = $this->db->getRow(sprintf(
            'SELECT `raw_payload` FROM `%sqamera_webhook_delivery` WHERE `delivery_id` = \'%s\'',
            $this->tablePrefix,
            $this->escape($deliveryId)
        ));

        if (!is_array($check) || !isset($check['raw_payload'])) {
            throw new RepositoryException('delivery row missing after insert');
        }

        return ((string) $check['raw_payload']) === $rawPayload
            ? DeliveryOutcome::ACCEPTED
            : DeliveryOutcome::DUPLICATE;
    }

    private function escape(string $value): string
    {
        return $this->db->escape($value, true, true);
    }
}
