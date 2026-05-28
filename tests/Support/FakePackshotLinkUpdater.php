<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Support;

use QameraAi\Module\Webhook\Event\PackshotLinkUpdater;
use QameraAi\Module\Webhook\Event\QameraDbException;

/**
 * Test double for {@see PackshotLinkUpdater}. Subclasses the real type so
 * handler unit tests can substitute it without touching {@see \Db}.
 * Records every UPSERT and lets the test pre-script the next call to
 * either succeed (with a configurable insert vs update bool) or throw.
 */
final class FakePackshotLinkUpdater extends PackshotLinkUpdater
{
    /** @var list<array<string, mixed>> */
    public array $upserts = [];

    public bool $nextReturnsInsert = true;
    public ?QameraDbException $throwNext = null;

    public function __construct()
    {
        // Bypass the parent constructor — this fake never touches a Db.
    }

    /**
     * @param array<string, mixed> $row
     */
    public function upsertByPackshotId(array $row): bool
    {
        if ($this->throwNext !== null) {
            $e = $this->throwNext;
            $this->throwNext = null;
            throw $e;
        }
        $this->upserts[] = $row;
        return $this->nextReturnsInsert;
    }
}
