<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Support;

use Db;
use PrestaShopDatabaseException;

/**
 * In-memory `Db` fake for repository tests. Records every executed SQL
 * statement and maintains a simple primary-key-indexed map for the
 * webhook delivery table so insert/duplicate semantics can be exercised
 * without MySQL.
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

    public function execute(string $sql, bool $useCache = true): bool
    {
        $this->executed[] = $sql;

        if ($this->throwOnExecute !== null) {
            $e = $this->throwOnExecute;
            $this->throwOnExecute = null;
            throw $e;
        }
        if ($this->failNextExecute) {
            $this->failNextExecute = false;
            return false;
        }

        // Pattern-match the webhook INSERT … ON DUPLICATE KEY UPDATE so we
        // can simulate PK collision semantics without parsing the SQL fully.
        if (preg_match(
            "/INSERT INTO `[^`]*qamera_webhook_delivery`.*?VALUES \('([^']+)', '([^']+)', '([^']+)', 'accepted', NULL, '(.*)'\) ON DUPLICATE KEY UPDATE/s",
            $sql,
            $m
        )) {
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
            }
            // On duplicate: do nothing (mirrors ON DUPLICATE KEY UPDATE id=id).
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

        if (preg_match(
            "/SELECT `(?:received_at|raw_payload|delivery_id)` FROM `[^`]*qamera_webhook_delivery` WHERE `delivery_id` = '([^']+)'/",
            $sql,
            $m
        )) {
            $deliveryId = $m[1];
            return $this->rows[$deliveryId] ?? false;
        }

        return false;
    }

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
