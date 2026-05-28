<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Support;

use QameraAi\Module\Packshot\PackshotJobUpdater;

/**
 * In-memory replacement for {@see PackshotJobUpdater} used by the four
 * `Job*Handler` tests. Records each `upsert()` call so assertions can
 * verify the handler invoked the per-job mirror path with the right
 * payload-derived fields. No DB activity.
 */
final class FakePackshotJobUpdater extends PackshotJobUpdater
{
    /** @var list<array<string, string|null>> */
    public array $upserts = [];

    /** Last exception to throw on the next upsert() call, then clear. */
    public ?\Throwable $throwOnNext = null;

    // phpcs:disable Generic.Files.LineLength
    public function __construct()
    {
        // Intentionally skip parent constructor — the fake replaces every
        // upsert() side effect, so the parent's $repository / $linkLookup /
        // $logger deps are never reached. This avoids forcing every handler
        // test to also wire a RecordingDb + SpyLogger pair just to satisfy
        // a typed constructor it would never exercise.
    }
    // phpcs:enable Generic.Files.LineLength

    public function upsert(
        string $eventType,
        string $deliveryId,
        string $qameraJobId,
        ?string $outputUrl,
        ?string $outputUrlExpiresAt,
        ?string $lastErrorMessage,
        ?string $payloadExternalRef,
        ?string $payloadOrderId
    ): void {
        if ($this->throwOnNext !== null) {
            $e = $this->throwOnNext;
            $this->throwOnNext = null;
            throw $e;
        }

        $this->upserts[] = [
            'event_type' => $eventType,
            'delivery_id' => $deliveryId,
            'qamera_job_id' => $qameraJobId,
            'output_url' => $outputUrl,
            'output_url_expires_at' => $outputUrlExpiresAt,
            'last_error_message' => $lastErrorMessage,
            'payload_external_ref' => $payloadExternalRef,
            'payload_order_id' => $payloadOrderId,
        ];
    }
}
