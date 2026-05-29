<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Packshot;

use PHPUnit\Framework\TestCase;
use QameraAi\Module\Api\Dto\SubmitJobRequest;
use QameraAi\Module\Api\Dto\SubmitJobResponse;
use QameraAi\Module\Api\Dto\SubmitJobResponseSubject;
use QameraAi\Module\Api\Dto\PackshotResponse;
use QameraAi\Module\Api\Dto\RegisterPackshotRequest;
use QameraAi\Module\Api\QameraApiClient;
use QameraAi\Module\Packshot\PackshotJobRow;
use QameraAi\Module\Packshot\PackshotJobSubmitter;
use QameraAi\Module\Packshot\PackshotJobUpdater;
use QameraAi\Module\Packshot\SubmitFormInput;
use QameraAi\Module\Packshot\SyncedProductLink;
use QameraAi\Module\Sync\PrestaShopLoggerWrapper;
use QameraAi\Module\Tests\Support\FakePackshotJobRepository;
use QameraAi\Module\Tests\Support\FakePackshotReviewRepository;
use QameraAi\Module\Tests\Support\FakeSyncedProductLinkLookup;
use QameraAi\Module\Tests\Support\SpyLogger;

/**
 * End-to-end wiring: form submit → upstream call → local row pending →
 * webhook `job.completed` → row flips to completed with output_url.
 *
 * Composes the real submitter + updater over the in-memory
 * {@see FakePackshotJobRepository} so a single persistent map is shared
 * across both write paths. The webhook side feeds the real wire fields
 * (`job.product_ref`, `job.id`) rather than the submit-side packshot ref.
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
            new FakePackshotReviewRepository(),
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
        // The submitter still mints a packshot_external_ref of the documented shape.
        self::assertNotNull($capturedRef);
        self::assertSame(1, preg_match('/^ps:1:42:packshot:[0-9a-f-]{36}$/', $capturedRef));

        // Webhook job.completed carries job.product_ref + job.id; the row
        // exists so the updater takes the UPDATE path.
        $logger = new SpyLogger();
        $updater = new PackshotJobUpdater($repo, $lookup, $logger);
        $updater->upsert(
            eventType: 'job.completed',
            deliveryId: 'd-1',
            qameraJobId: 'job-uuid-1',
            outputUrl: 'https://cdn.example.com/out.jpg',
            outputUrlExpiresAt: '2026-06-01 00:00:00',
            lastErrorMessage: null,
            productRef: 'ps:1:42',
            orderId: 'ord-1',
        );

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
        $submitter = new PackshotJobSubmitter(
            $apiClient,
            $repo,
            $lookup,
            new FakePackshotReviewRepository(),
            $this->silentLogger()
        );
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
            productRef: null,
            orderId: null,
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

        // Webhook arrives BEFORE the submitter persisted anything — the
        // updater recovers the FK from job.product_ref and inserts a stub.
        $logger = new SpyLogger();
        $updater = new PackshotJobUpdater($repo, $lookup, $logger);
        $updater->upsert(
            eventType: 'job.completed',
            deliveryId: 'd-3',
            qameraJobId: 'orphan-job',
            outputUrl: 'https://cdn.example.com/out.jpg',
            outputUrlExpiresAt: '2026-06-01 00:00:00',
            lastErrorMessage: null,
            productRef: 'ps:1:42',
            orderId: 'ord-orphan',
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

            public function registerPackshot(RegisterPackshotRequest $request): PackshotResponse
            {
                // Input-packshot pre-flight (D1). Stubbed: never opens a socket.
                return new PackshotResponse($request->externalRef, 'prod-stub', 'pack-stub', 'created');
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
