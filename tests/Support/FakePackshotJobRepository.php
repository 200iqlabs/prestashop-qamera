<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Support;

use QameraAi\Module\Packshot\JobsGridFilters;
use QameraAi\Module\Packshot\PackshotJobRepository;
use QameraAi\Module\Packshot\PackshotJobRow;
use QameraAi\Module\Packshot\PackshotJobWebhookUpdate;
use QameraAi\Module\Webhook\Event\QameraDbException;

/**
 * In-memory replacement for {@see PackshotJobRepository}. Stores rows in
 * a plain array so unit tests can verify what the submitter persists
 * without provisioning a database.
 */
final class FakePackshotJobRepository extends PackshotJobRepository
{
    /** @var PackshotJobRow[] */
    public array $insertedRows = [];

    /** @var PackshotJobWebhookUpdate[] */
    public array $webhookUpserts = [];

    public ?QameraDbException $throwOnInsert = null;

    public function __construct()
    {
        // Bypass parent — no Db dependency in the fake.
    }

    public function findByJobId(string $qameraJobId): ?PackshotJobRow
    {
        foreach ($this->insertedRows as $row) {
            if ($row->qameraJobId === $qameraJobId) {
                return $row;
            }
        }
        return null;
    }

    public function findByExternalRef(string $ref): ?PackshotJobRow
    {
        foreach ($this->insertedRows as $row) {
            if ($row->packshotExternalRef === $ref) {
                return $row;
            }
        }
        return null;
    }

    /**
     * @param PackshotJobRow[] $rows
     */
    public function insertBatch(array $rows): void
    {
        if ($this->throwOnInsert !== null) {
            $e = $this->throwOnInsert;
            $this->throwOnInsert = null;
            throw $e;
        }
        foreach ($rows as $row) {
            $this->insertedRows[] = $row;
        }
    }

    public function upsertFromWebhook(PackshotJobWebhookUpdate $update): void
    {
        $this->webhookUpserts[] = $update;
    }

    public function listForGrid(JobsGridFilters $filters): array
    {
        return [];
    }
}
