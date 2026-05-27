<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Support;

use Db;

/**
 * In-memory `Db` fake for repository tests. Records every executed SQL
 * statement, maintains a primary-key-indexed map for the webhook
 * delivery table, and tracks the affected-row count of the last
 * `execute()` so callers can exercise the repository's
 * `INSERT … ON DUPLICATE KEY UPDATE` semantics without MySQL.
 */
final class FakeDb extends Db
{
    /** @var list<string> */
    public array $executed = [];

    /** @var array<string, array<string, string>> Keyed by delivery_id. */
    public array $rows = [];

    public bool $failNextExecute = false;
    public bool $failNextGetRow = false;

    /** @var \Throwable|null Set to throw on the next execute(). */
    public ?\Throwable $throwOnExecute = null;

    /**
     * Mirrors MySQL's mysql_affected_rows for the last `execute()`:
     *   1 — fresh insert
     *   0 — ON DUPLICATE KEY UPDATE no-op (delivery_id=delivery_id keeps
     *       the row unchanged so MySQL reports zero affected)
     */
    private int $lastAffectedRows = 0;

    public function execute(string $sql, bool $useCache = true): bool
    {
        $this->executed[] = $sql;
        $this->lastAffectedRows = 0;

        if ($this->throwOnExecute !== null) {
            $e = $this->throwOnExecute;
            $this->throwOnExecute = null;
            throw $e;
        }
        if ($this->failNextExecute) {
            $this->failNextExecute = false;
            return false;
        }

        $insertPattern = "/INSERT INTO `[^`]*qamera_webhook_delivery`.*?VALUES "
            . "\('([^']+)', '([^']+)', '([^']+)', 'accepted', NULL, '(.*)'\)"
            . " ON DUPLICATE KEY UPDATE/s";
        if (preg_match($insertPattern, $sql, $m)) {
            $deliveryId = $m[1];
            if (!isset($this->rows[$deliveryId])) {
                $this->rows[$deliveryId] = [
                    'delivery_id' => $deliveryId,
                    'received_at' => $m[2],
                    'event_type' => $m[3],
                    'status' => 'accepted',
                    'last_error_message' => '',
                    'raw_payload' => $m[4],
                ];
                $this->lastAffectedRows = 1;
            }
            // Duplicate: row stays unchanged, affected_rows = 0.
        }

        return true;
    }

    public function executeS(string $sql, bool $array = true, bool $useCache = true)
    {
        $this->executed[] = $sql;
        return [];
    }

    public function getRow(string $sql, bool $useCache = true)
    {
        $this->executed[] = $sql;

        if ($this->failNextGetRow) {
            $this->failNextGetRow = false;
            return false;
        }

        $selectPattern = "/SELECT `(?:received_at|raw_payload|delivery_id)`"
            . " FROM `[^`]*qamera_webhook_delivery`"
            . " WHERE `delivery_id` = '([^']+)'/";
        if (preg_match($selectPattern, $sql, $m)) {
            $deliveryId = $m[1];
            return $this->rows[$deliveryId] ?? false;
        }

        return false;
    }

    // phpcs:disable PSR1.Methods.CamelCapsMethodName
    public function Affected_Rows(): int
    {
        return $this->lastAffectedRows;
    }
    // phpcs:enable PSR1.Methods.CamelCapsMethodName

    public function seedRow(string $deliveryId, string $receivedAt, string $eventType, string $rawPayload): void
    {
        $this->rows[$deliveryId] = [
            'delivery_id' => $deliveryId,
            'received_at' => $receivedAt,
            'event_type' => $eventType,
            'status' => 'accepted',
            'last_error_message' => '',
            'raw_payload' => $rawPayload,
        ];
    }
}
