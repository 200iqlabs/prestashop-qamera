<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Support;

use QameraAi\Module\Packshot\Acceptance\PackshotReviewRepository;
use QameraAi\Module\Packshot\Acceptance\PackshotReviewRow;
use QameraAi\Module\Webhook\Event\QameraDbException;

/**
 * In-memory replacement for {@see PackshotReviewRepository}. Lets unit tests
 * pre-seed accepted `product_ref`s and inspect upserts/votes without a DB.
 */
final class FakePackshotReviewRepository extends PackshotReviewRepository
{
    /** @var array<string, true> product_refs with a voting='accepted' row */
    public array $acceptedRefs = [];

    /** @var PackshotReviewRow[] */
    public array $upserts = [];

    /** @var array<int, array{job_id: string, voting: string, voting_at: string}> */
    public array $votes = [];

    public ?QameraDbException $throwOnHasAccepted = null;

    public function __construct()
    {
        // Bypass parent — no Db dependency in the fake.
    }

    public function hasAcceptedForProductRef(string $productRef): bool
    {
        if ($this->throwOnHasAccepted !== null) {
            $e = $this->throwOnHasAccepted;
            $this->throwOnHasAccepted = null;
            throw $e;
        }
        return isset($this->acceptedRefs[$productRef]);
    }

    public function upsertFromWebhook(PackshotReviewRow $row): void
    {
        $this->upserts[] = $row;
    }

    public function setVoting(string $qameraJobId, string $voting, string $votingAt): void
    {
        $this->votes[] = ['job_id' => $qameraJobId, 'voting' => $voting, 'voting_at' => $votingAt];
    }

    public function findByJobId(string $qameraJobId): ?PackshotReviewRow
    {
        foreach ($this->upserts as $row) {
            if ($row->qameraJobId === $qameraJobId) {
                return $row;
            }
        }
        return null;
    }

    public function listPending(int $idLang): array
    {
        return [];
    }
}
