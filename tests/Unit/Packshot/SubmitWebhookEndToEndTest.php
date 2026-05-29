<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Packshot;

use PHPUnit\Framework\TestCase;
use QameraAi\Module\Api\Dto\SubmitJobRequest;
use QameraAi\Module\Api\Dto\SubmitJobResponse;
use QameraAi\Module\Api\Dto\SubmitJobResponseSubject;
use QameraAi\Module\Api\QameraApiClient;
use QameraAi\Module\Packshot\PackshotJobRow;
use QameraAi\Module\Packshot\PackshotJobSubmitter;
use QameraAi\Module\Packshot\PackshotJobUpdater;
use QameraAi\Module\Packshot\SubmitFormInput;
use QameraAi\Module\Packshot\SyncedProductLink;
use QameraAi\Module\Sync\PrestaShopLoggerWrapper;
use QameraAi\Module\Tests\Support\FakePackshotJobRepository;
use QameraAi\Module\Tests\Support\FakeSyncedProductLinkLookup;
use QameraAi\Module\Tests\Support\SpyLogger;
use QameraAi\Module\Webhook\Event\PackshotExternalRefParser;

/**
 * End-to-end wiring: form submit → upstream call → local row pending →
 * webhook `job.completed` → row flips to completed with output_url.
 *
 * Composes the real submitter + updater over the in-memory
 * {@see FakePackshotJobRepository} so a single persistent map is shared
 * across both write paths. Demonstrates the spec scenario from
 * `packshot-jobs` (insert one row per returned job_id) and from
 * `webhook-handler` (update existing row on `job.completed`).
 *
 * This is the integration coverage promised by tasks.md §13.1 — the
 * larger PS-bootstrap-required variant (with Db / Configuration etc.)
 * lives in `tests/Integration/Webhook/WebhookDispatchIntegrationTest.php`
 * and is marked incomplete pending a docker harness.
 */
final class SubmitWebhookEndToEndTest extends TestCase
{
    public function testSubmitThenCompletedWebhookFlipsRowToCompleted(): void
    {
        $repo = new FakePackshotJobRepository();
        $lookup = new FakeSyncedProductLinkLookup();
        $lookup->byIdProduct[42] = new SyncedProductLink(
            idLink: 99,
            idShop: 1,
            idProduct: 42,
            qameraAssetId: 'asset-42',
            qameraProductRef: 'ps:1:42',
            displayNameSnapshot: 'Widget',
            analysisStatus: SyncedProductLink::ANALYSIS_STATUS_DESCRIBED,
        );

        $capturedRef = null;
        $apiClient = $this->stubClient(
            function (SubmitJobRequest $req) use (&$capturedRef): SubmitJobResponse {
                $capturedRef = $req->subjects[0]->packshotExternalRef;
                return new SubmitJobResponse('ord-1', 'queued', [
                    new SubmitJobResponseSubject('ps:1:42', ['job-uuid-1']),
                ]);
            }
        );

        $submitter = new PackshotJobSubmitter(
            $apiClient,
            $repo,
            $lookup,
            $this->silentLogger(),
        );

        $result = $submitter->submit(new SubmitFormInput(
            idShop: 1,
            productIds: [42],
            aiModel: 'openai/gpt-image-1',
            aspectRatio: '1:1',
            imagesCount: 1,
        ));

        // Pending row landed.
        self::assertTrue($result->isFullSuccess());
        self::assertCount(1, $repo->insertedRows);
        $pending = $repo->insertedRows[0];
        self::assertSame('job-uuid-1', $pending->qameraJobId);
        self::assertSame(PackshotJobRow::STATUS_PENDING, $pending->status);
        self::assertNull($pending->outputUrl);
        self::assertNotNull($capturedRef);

        // Verify the generated ref matches the spec shape, then feed it
        // back as if the webhook upstream echoed it on the delivery.
        $parsed = PackshotExternalRefParser::parse($capturedRef);
        self::assertSame(1, $parsed->shopId);
        self::assertSame(42, $parsed->productId);

        // Now exercise the webhook upsert path. The updater finds the
        // existing row by qamera_job_id and flips it to completed.
        $logger = new SpyLogger();
        $updater = new PackshotJobUpdater($repo, $lookup, $logger);
        $updater->upsert(
            eventType: 'job.completed',
            deliveryId: 'd-1',
            qameraJobId: 'job-uuid-1',
            outputUrl: 'https://cdn.example.com/out.jpg',
            outputUrlExpiresAt: '2026-06-01 00:00:00',
            lastErrorMessage: null,
            payloadExternalRef: $capturedRef,
            payloadOrderId: 'ord-1',
        );

        // The fake repository records webhook upserts separately from
        // the submitter's batch inserts; assert the upsert landed with
        // the right status mapping.
        self::assertCount(1, $repo->webhookUpserts);
        $upsert = $repo->webhookUpserts[0];
        self::assertSame('job-uuid-1', $upsert->qameraJobId);
        self::assertSame(PackshotJobRow::STATUS_COMPLETED, $upsert->status);
        self::assertSame('https://cdn.example.com/out.jpg', $upsert->outputUrl);
        self::assertSame('2026-06-01 00:00:00', $upsert->outputUrlExpiresAt);
        self::assertNull($upsert->lastErrorMessage);

        // No pre-submit race log noise on the happy path.
        self::assertSame([], $logger->entriesAtLevel('warning'));
    }

    public function testFailedWebhookRecordsErrorMessage(): void
    {
        $repo = new FakePackshotJobRepository();
        $lookup = new FakeSyncedProductLinkLookup();
        $lookup->byIdProduct[42] = new SyncedProductLink(
            idLink: 99,
            idShop: 1,
            idProduct: 42,
            qameraAssetId: 'asset-42',
            qameraProductRef: 'ps:1:42',
            displayNameSnapshot: 'Widget',
            analysisStatus: SyncedProductLink::ANALYSIS_STATUS_DESCRIBED,
        );

        $apiClient = $this->stubClient(static function (): SubmitJobResponse {
            return new SubmitJobResponse('ord-1', 'queued', [
                new SubmitJobResponseSubject('ps:1:42', ['job-X']),
            ]);
        });
        $submitter = new PackshotJobSubmitter($apiClient, $repo, $lookup, $this->silentLogger());
        $submitter->submit(new SubmitFormInput(
            idShop: 1,
            productIds: [42],
            aiModel: 'openai/gpt-image-1',
            aspectRatio: '1:1',
            imagesCount: 1,
        ));

        $updater = new PackshotJobUpdater($repo, $lookup, new SpyLogger());
        $updater->upsert(
            eventType: 'job.failed',
            deliveryId: 'd-2',
            qameraJobId: 'job-X',
            outputUrl: null,
            outputUrlExpiresAt: null,
            lastErrorMessage: 'quota exceeded',
            payloadExternalRef: null,
            payloadOrderId: null,
        );

        self::assertCount(1, $repo->webhookUpserts);
        $upsert = $repo->webhookUpserts[0];
        self::assertSame(PackshotJobRow::STATUS_FAILED, $upsert->status);
        self::assertSame('quota exceeded', $upsert->lastErrorMessage);
    }

    public function testPreSubmitRaceInsertsStubViaWebhook(): void
    {
        $repo = new FakePackshotJobRepository();
        $lookup = new FakeSyncedProductLinkLookup();
        $lookup->byIdProduct[42] = new SyncedProductLink(
            idLink: 99,
            idShop: 1,
            idProduct: 42,
            qameraAssetId: 'asset-42',
            qameraProductRef: 'ps:1:42',
            displayNameSnapshot: 'Widget',
            analysisStatus: SyncedProductLink::ANALYSIS_STATUS_DESCRIBED,
        );

        // Webhook arrives BEFORE the submitter has persisted anything —
        // the updater should recover the FK from the external_ref and
        // insert a stub.
        $logger = new SpyLogger();
        $updater = new PackshotJobUpdater($repo, $lookup, $logger);
        $updater->upsert(
            eventType: 'job.completed',
            deliveryId: 'd-3',
            qameraJobId: 'orphan-job',
            outputUrl: 'https://cdn.example.com/out.jpg',
            outputUrlExpiresAt: '2026-06-01 00:00:00',
            lastErrorMessage: null,
            payloadExternalRef: 'ps:1:42:packshot:11111111-2222-3333-4444-555555555555',
            payloadOrderId: 'ord-orphan',
        );

        self::assertCount(1, $repo->webhookUpserts);
        $upsert = $repo->webhookUpserts[0];
        self::assertSame('orphan-job', $upsert->qameraJobId);
        self::assertSame(PackshotJobRow::STATUS_COMPLETED, $upsert->status);
        self::assertSame(99, $upsert->fallbackIdQameraProductLink);
        self::assertSame('ord-orphan', $upsert->fallbackQameraOrderId);

        $info = $logger->entriesAtLevel('info');
        $messages = array_column($info, 'message');
        self::assertContains('pre_submit_webhook_upsert', $messages);
    }

    /**
     * @param callable(SubmitJobRequest): SubmitJobResponse $handler
     */
    private function stubClient(callable $handler): QameraApiClient
    {
        return new class ($handler) extends QameraApiClient {
            /** @var callable(SubmitJobRequest): SubmitJobResponse */
            private $handler;

            public function __construct(callable $handler)
            {
                $this->handler = $handler;
            }

            public function submitJob(SubmitJobRequest $request): SubmitJobResponse
            {
                return ($this->handler)($request);
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
                // intentional no-op
            }
        };
    }
}
