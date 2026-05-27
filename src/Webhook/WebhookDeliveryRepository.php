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
 * fresh insert and `0` for the no-op-update branch, which distinguishes
 * `accepted` from `duplicate` without a probe-before-insert SELECT.
 *
 * Caveat: this distinction depends on the connection NOT being opened
 * with `MYSQLI_CLIENT_FOUND_ROWS` / `PDO::MYSQL_ATTR_FOUND_ROWS`. Under
 * those flags MySQL reports matched rows instead of changed rows, so a
 * no-op UPDATE would surface as `1` and silently break duplicate
 * detection. PrestaShop core opens its connections without those flags,
 * so we're safe — but a future operator-set PDO option would regress
 * this. If you change the DB driver options, re-run this test suite.
 *
 * On the duplicate path the repository runs ONE follow-up SELECT to
 * fetch the original row's `received_at` so the handler can satisfy the
 * spec's "Operator-visible logging → Duplicate" requirement (warning
 * log line MUST include the original received_at). This second query
 * runs only on the rare duplicate path; the happy path stays at one
 * round-trip.
 *
 * DB exceptions surface to the caller (the controller emits 500 per D10).
 */
final class WebhookDeliveryRepository
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
    ): DeliveryRecordResult {
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

        $affected = (int) $this->db->Affected_Rows();
        if ($affected >= 1) {
            return new DeliveryRecordResult(DeliveryOutcome::ACCEPTED, $receivedAt);
        }

        // Duplicate path: read back the original `received_at` so the
        // handler can include it in the warning log line per spec.
        $row = $this->db->getRow(sprintf(
            'SELECT `received_at` FROM `%sqamera_webhook_delivery` WHERE `delivery_id` = \'%s\'',
            $this->tablePrefix,
            $this->escape($deliveryId)
        ));

        $originalReceivedAt = (is_array($row) && isset($row['received_at']))
            ? (string) $row['received_at']
            : $receivedAt;

        return new DeliveryRecordResult(DeliveryOutcome::DUPLICATE, $originalReceivedAt);
    }

    private function escape(string $value): string
    {
        return $this->db->escape($value, true, true);
    }
}
