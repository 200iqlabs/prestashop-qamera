<?php

declare(strict_types=1);

namespace QameraAi\Module\Packshot\Acceptance;

use QameraAi\Module\Api\Exception\ApiException;
use QameraAi\Module\Api\QameraApiClient;
use QameraAi\Module\Sync\PrestaShopLoggerWrapper;

/**
 * Applies an operator's accept/reject decision on a pending packshot review.
 *
 * Order is load-bearing (add-packshot-acceptance-flow, "Operator vote"
 * requirement): the upstream `POST /jobs/{id}/accept|reject` runs FIRST, and
 * the local `ps_qamera_packshot_review.voting` flips ONLY after a 2xx. An
 * {@see ApiException} (e.g. `409 job_not_completed`) propagates before the
 * local write, so the row stays `pending` and the controller surfaces the
 * error — the local state never drifts ahead of the server cascade onto
 * `product_packshots.voting`.
 */
class PackshotVoteService
{
    public function __construct(
        private readonly QameraApiClient $apiClient,
        private readonly PackshotReviewRepository $repository,
        private readonly PrestaShopLoggerWrapper $logger,
    ) {
    }

    /**
     * @throws ApiException                                          on upstream failure (row stays pending)
     * @throws \QameraAi\Module\Webhook\Event\QameraDbException      on local write failure (after a successful vote)
     */
    public function accept(string $qameraJobId): void
    {
        $this->apiClient->acceptJob($qameraJobId);
        $this->repository->setVoting(
            $qameraJobId,
            PackshotReviewRow::VOTING_ACCEPTED,
            gmdate('Y-m-d H:i:s')
        );
        $this->log('packshot_review_accepted', $qameraJobId);
    }

    /**
     * @throws ApiException                                          on upstream failure (row stays pending)
     * @throws \QameraAi\Module\Webhook\Event\QameraDbException      on local write failure (after a successful vote)
     */
    public function reject(string $qameraJobId): void
    {
        $this->apiClient->rejectJob($qameraJobId);
        $this->repository->setVoting(
            $qameraJobId,
            PackshotReviewRow::VOTING_REJECTED,
            gmdate('Y-m-d H:i:s')
        );
        $this->log('packshot_review_rejected', $qameraJobId);
    }

    private function log(string $message, string $qameraJobId): void
    {
        $this->logger->addLog(
            '[QameraAi][packshot-review] ' . $message . ' qamera_job_id=' . $qameraJobId,
            1,
            null,
            'QameraAiModule',
            null,
            true
        );
    }
}
