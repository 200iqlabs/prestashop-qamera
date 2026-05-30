<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Support;

use Db;

/**
 * Lightweight Db test double: records every executed statement, lets
 * the test pre-set `affectedRowsScript` (a queue of values returned by
 * successive `Affected_Rows()` calls), and supports failing the next
 * execute either by returning false or throwing.
 *
 * Differs from {@see FakeDb} in that it does no semantic SQL parsing —
 * it is the right tool for unit tests of generic UPDATE / INSERT
 * statements where the test only cares about the statement that was
 * issued, not about a simulated in-memory row store.
 */
final class RecordingDb extends Db
{
    /** @var list<string> */
    public array $executed = [];

    /** @var list<int> Queue of affected-row counts. */
    public array $affectedRowsScript = [];

    /** @var list<array<string, mixed>|false> Queue of getRow() return values. */
    public array $getRowScript = [];

    /** @var list<array<int, array<string, mixed>>> Queue of executeS() result sets. */
    public array $executeSScript = [];

    public bool $failNextExecute = false;
    public ?\Throwable $throwOnExecute = null;

    private int $lastAffectedRows = 0;

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
            $this->lastAffectedRows = 0;
            return false;
        }

        $this->lastAffectedRows = array_shift($this->affectedRowsScript) ?? 0;
        return true;
    }

    public function executeS(string $sql, bool $array = true, bool $useCache = true)
    {
        $this->executed[] = $sql;
        if ($this->executeSScript === []) {
            return [];
        }
        return array_shift($this->executeSScript);
    }

    public function getRow(string $sql, bool $useCache = true)
    {
        $this->executed[] = $sql;
        if ($this->getRowScript === []) {
            return false;
        }
        return array_shift($this->getRowScript);
    }

    // phpcs:disable PSR1.Methods.CamelCapsMethodName
    public function Affected_Rows(): int
    {
        return $this->lastAffectedRows;
    }
    // phpcs:enable PSR1.Methods.CamelCapsMethodName

    public function lastExecuted(): string
    {
        return $this->executed[count($this->executed) - 1] ?? '';
    }
}
