<?php

declare(strict_types=1);

namespace QameraAi\Module\Packshot\Acceptance;

use QameraAi\Module\Webhook\Event\QameraDbException;
use QameraAi\Module\Webhook\WebhookLogger;

/**
 * Routes a completed `job_type='packshot'` webhook delivery into a
 * `ps_qamera_packshot_review` UPSERT (voting='pending'), keyed on the wire
 * body's `job.id`. Sibling of {@see \QameraAi\Module\Packshot\PackshotJobUpdater}
 * for the acceptance flow: the job mirror tracks lifecycle, this tracks the
 * operator's pending review queue.
 *
 * `product_ref` is stored in canonical `ps:<shop>:<product>` form
 * (reconstructed from the parsed ids) so the photo-shoot grid gate's
 * `hasAcceptedForProductRef` join matches `SyncedProductLink.qameraProductRef`
 * byte-for-byte.
 *
 * NOT `final` so the existing handler-test pattern can subclass it as a fake.
 */
class PackshotReviewWriter
{
    public function __construct(
        private readonly PackshotReviewRepository $repository,
        private readonly WebhookLogger $logger,
    ) {
    }

    /**
     * @throws QameraDbException on DB error (the dispatcher catches it)
     */
    public function recordPending(
        string $deliveryId,
        string $qameraJobId,
        int $idShop,
        int $idProduct,
        ?string $assetUrl
    ): void {
        $productRef = sprintf('ps:%d:%d', $idShop, $idProduct);

        $this->repository->upsertFromWebhook(new PackshotReviewRow(
            id: null,
            qameraJobId: $qameraJobId,
            idShop: $idShop,
            idProduct: $idProduct,
            productRef: $productRef,
            assetUrl: $assetUrl,
            voting: PackshotReviewRow::VOTING_PENDING,
            votingAt: null,
            generatedAt: gmdate('Y-m-d H:i:s'),
        ));

        $this->logger->info('packshot_review_pending_recorded', [
            'delivery_id' => $deliveryId,
            'qamera_job_id' => $qameraJobId,
            'product_ref' => $productRef,
            'has_preview' => $assetUrl !== null ? '1' : '0',
        ]);
    }
}
