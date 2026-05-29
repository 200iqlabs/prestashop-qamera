<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Packshot\Acceptance;

use PHPUnit\Framework\TestCase;
use QameraAi\Module\Api\Exception\ValidationException;
use QameraAi\Module\Api\QameraApiClient;
use QameraAi\Module\Packshot\Acceptance\PackshotReviewRow;
use QameraAi\Module\Packshot\Acceptance\PackshotVoteService;
use QameraAi\Module\Sync\PrestaShopLoggerWrapper;
use QameraAi\Module\Tests\Support\FakePackshotReviewRepository;

final class PackshotVoteServiceTest extends TestCase
{
    public function testAcceptCallsApiThenFlipsLocalVoting(): void
    {
        $repo = new FakePackshotReviewRepository();
        $client = $this->stubClient();
        $service = new PackshotVoteService($client, $repo, $this->silentLogger());

        $service->accept('job-1');

        self::assertSame(['accept' => ['job-1']], $client->calls);
        self::assertCount(1, $repo->votes);
        self::assertSame('job-1', $repo->votes[0]['job_id']);
        self::assertSame(PackshotReviewRow::VOTING_ACCEPTED, $repo->votes[0]['voting']);
        self::assertNotSame('', $repo->votes[0]['voting_at']);
    }

    public function testRejectCallsApiThenFlipsLocalVoting(): void
    {
        $repo = new FakePackshotReviewRepository();
        $client = $this->stubClient();
        $service = new PackshotVoteService($client, $repo, $this->silentLogger());

        $service->reject('job-2');

        self::assertSame(['reject' => ['job-2']], $client->calls);
        self::assertSame(PackshotReviewRow::VOTING_REJECTED, $repo->votes[0]['voting']);
    }

    public function testApiFailureLeavesVotingUntouchedAndPropagates(): void
    {
        $repo = new FakePackshotReviewRepository();
        $client = $this->stubClient(new ValidationException('job_not_completed', 409));
        $service = new PackshotVoteService($client, $repo, $this->silentLogger());

        try {
            $service->accept('job-3');
            self::fail('expected ValidationException to propagate');
        } catch (ValidationException $e) {
            self::assertSame('job_not_completed', $e->getMessage());
        }

        // The local row must NOT have been flipped — it stays pending.
        self::assertSame([], $repo->votes);
    }

    private function stubClient(?\Throwable $throw = null): QameraApiClient
    {
        return new class ($throw) extends QameraApiClient {
            /** @var array<string, list<string>> */
            public array $calls = [];
            private ?\Throwable $throw;

            public function __construct(?\Throwable $throw)
            {
                $this->throw = $throw;
                // Bypass parent ctor — no HTTP socket.
            }

            public function acceptJob(string $qameraJobId): void
            {
                if ($this->throw !== null) {
                    throw $this->throw;
                }
                $this->calls['accept'][] = $qameraJobId;
            }

            public function rejectJob(string $qameraJobId): void
            {
                if ($this->throw !== null) {
                    throw $this->throw;
                }
                $this->calls['reject'][] = $qameraJobId;
            }
        };
    }

    private function silentLogger(): PrestaShopLoggerWrapper
    {
        return new class extends PrestaShopLoggerWrapper {
            public function addLog(
                string $message,
                int $severity = 1,
                ?int $errorCode = null,
                ?string $objectType = null,
                ?int $objectId = null,
                bool $allowDuplicate = false
            ): void {
            }
        };
    }
}
