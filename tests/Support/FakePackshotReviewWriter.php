<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Support;

use QameraAi\Module\Packshot\Acceptance\PackshotReviewWriter;
use QameraAi\Module\Webhook\Event\QameraDbException;

/**
 * Records {@see PackshotReviewWriter::recordPending} calls so the webhook
 * handler test can assert the review branch fired (or did not) without a DB.
 */
final class FakePackshotReviewWriter extends PackshotReviewWriter
{
    /** @var array<int, array<string, mixed>> */
    public array $recorded = [];

    public ?QameraDbException $throwOnNext = null;

    public function __construct()
    {
        // Bypass parent — no repository/logger in the fake.
    }

    public function recordPending(
        string $deliveryId,
        string $qameraJobId,
        int $idShop,
        int $idProduct,
        ?string $assetUrl
    ): void {
        if ($this->throwOnNext !== null) {
            $e = $this->throwOnNext;
            $this->throwOnNext = null;
            throw $e;
        }
        $this->recorded[] = [
            'delivery_id' => $deliveryId,
            'qamera_job_id' => $qameraJobId,
            'id_shop' => $idShop,
            'id_product' => $idProduct,
            'asset_url' => $assetUrl,
        ];
    }
}
