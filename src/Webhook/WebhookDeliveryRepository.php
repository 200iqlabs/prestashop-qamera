<?php

declare(strict_types=1);

namespace QameraAi\Module\Webhook;

use Db;

/**
 * Persists accepted webhook deliveries to `{prefix}qamera_webhook_delivery`.
 *
 * The PK on `delivery_id` does the actual serialisation: `INSERT … ON
 * DUPLICATE KEY UPDATE delivery_id = delivery_id` is a no-op on collision,
 * so concurrent inserts with the same id produce exactly one row. The
 * outcome is read off `Db::Affected_Rows()` — MySQL reports `1` for a
 * fresh insert and `0` for the no-op-update branch, which lets us
 * distinguish `accepted` from `duplicate` without a follow-up SELECT.
 *
 * This is critical for the byte-identical-retry case: an earlier version
 * disambiguated via re-reading `raw_payload`, which incorrectly returned
 * `accepted` for both racers when the retry payload was identical to the
 * original (the normal at-least-once delivery semantics).
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

        // Affected_Rows() after INSERT … ON DUPLICATE KEY UPDATE col=col:
        // MySQL/MariaDB returns 1 for a fresh insert and 0 for the no-op
        // update branch — the `col=col` self-assignment leaves the row
        // unchanged so the update path reports zero affected rows. This
        // distinguishes accepted-vs-duplicate without a follow-up SELECT.
        $affected = (int) $this->db->Affected_Rows();

        return $affected >= 1
            ? DeliveryOutcome::ACCEPTED
            : DeliveryOutcome::DUPLICATE;
    }

    private function escape(string $value): string
    {
        return $this->db->escape($value, true, true);
    }
}
