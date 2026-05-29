<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Packshot;

use PHPUnit\Framework\TestCase;
use QameraAi\Module\Api\Dto\PackshotResponse;
use QameraAi\Module\Api\Dto\RegisterPackshotRequest;
use QameraAi\Module\Api\Dto\SubmitJobRequest;
use QameraAi\Module\Api\Dto\SubmitJobResponse;
use QameraAi\Module\Api\Dto\SubmitJobResponseSubject;
use QameraAi\Module\Api\Exception\ServerException;
use QameraAi\Module\Api\Exception\ValidationException;
use QameraAi\Module\Api\QameraApiClient;
use QameraAi\Module\Packshot\PackshotJobRow;
use QameraAi\Module\Packshot\PackshotJobSubmitter;
use QameraAi\Module\Packshot\SubmitFormInput;
use QameraAi\Module\Packshot\SyncedProductLink;
use QameraAi\Module\Sync\PrestaShopLoggerWrapper;
use QameraAi\Module\Tests\Support\FakePackshotJobRepository;
use QameraAi\Module\Tests\Support\FakeSyncedProductLinkLookup;

final class PackshotJobSubmitterTest extends TestCase
{
    public function testSingleSubjectSinglesImagePersistsOneRow(): void
    {
        $lookup = new FakeSyncedProductLinkLookup();
        $lookup->byIdProduct[42] = $this->link(42);

        $repo = new FakePackshotJobRepository();
        $client = $this->stubClient(function (SubmitJobRequest $req): SubmitJobResponse {
            return new SubmitJobResponse(
                orderId: 'ord-1',
                status: 'queued',
                subjects: [
                    new SubmitJobResponseSubject(productRef: 'ps:1:42', jobIds: ['j1']),
                ],
            );
        });
        $submitter = $this->submitter($client, $repo, $lookup);

        $result = $submitter->submit($this->input([42], imagesCount: 1));

        self::assertSame(1, $result->sessionsSubmitted);
        self::assertSame(0, $result->sessionsFailed);
        self::assertSame(1, $result->jobsPersisted);
        self::assertSame(['ord-1'], $result->orderIds);
        self::assertCount(1, $repo->insertedRows);
        self::assertSame('j1', $repo->insertedRows[0]->qameraJobId);
        self::assertSame(PackshotJobRow::STATUS_PENDING, $repo->insertedRows[0]->status);
    }

    public function testBulkFivePxImagesCountTwoPersistsTenRows(): void
    {
        $lookup = new FakeSyncedProductLinkLookup();
        for ($id = 1; $id <= 5; $id++) {
            $lookup->byIdProduct[$id] = $this->link($id);
        }

        $repo = new FakePackshotJobRepository();
        $client = $this->stubClient(function (SubmitJobRequest $req): SubmitJobResponse {
            $subjects = [];
            foreach ($req->subjects as $s) {
                $subjects[] = new SubmitJobResponseSubject(
                    productRef: $s->productRef,
                    jobIds: [$s->productRef . '-jA', $s->productRef . '-jB'],
                );
            }
            return new SubmitJobResponse('ord-bulk', 'queued', $subjects);
        });
        $submitter = $this->submitter($client, $repo, $lookup);

        $result = $submitter->submit($this->input([1, 2, 3, 4, 5], imagesCount: 2));

        self::assertSame(10, $result->jobsPersisted);
        self::assertCount(10, $repo->insertedRows);
        // All rows share one order_id
        $orderIds = array_unique(array_map(
            static fn (PackshotJobRow $r): string => $r->qameraOrderId,
            $repo->insertedRows
        ));
        self::assertSame(['ord-bulk'], array_values($orderIds));
    }

    public function testValidationErrorLeavesDbUntouchedAndReportsFailure(): void
    {
        $lookup = new FakeSyncedProductLinkLookup();
        $lookup->byIdProduct[42] = $this->link(42);

        $repo = new FakePackshotJobRepository();
        $client = $this->stubClient(static function (): SubmitJobResponse {
            throw new ValidationException('images_count must be ≤ 50', 422);
        });
        $submitter = $this->submitter($client, $repo, $lookup);

        $result = $submitter->submit($this->input([42], imagesCount: 4));

        self::assertSame(0, $result->sessionsSubmitted);
        self::assertSame(1, $result->sessionsFailed);
        self::assertSame(0, $result->jobsPersisted);
        self::assertSame([], $repo->insertedRows);
        self::assertArrayHasKey(1, $result->chunkFailures);
        self::assertStringContainsString('images_count', $result->chunkFailures[1]);
    }

    public function testServerErrorLeavesDbUntouched(): void
    {
        $lookup = new FakeSyncedProductLinkLookup();
        $lookup->byIdProduct[42] = $this->link(42);

        $repo = new FakePackshotJobRepository();
        $client = $this->stubClient(static function (): SubmitJobResponse {
            throw new ServerException('upstream timeout', 503);
        });
        $submitter = $this->submitter($client, $repo, $lookup);

        $result = $submitter->submit($this->input([42], imagesCount: 4));

        self::assertTrue($result->isFullFailure());
        self::assertSame([], $repo->insertedRows);
    }

    public function testChunkingSplitsAt100SubjectsAndProducesDistinctIdempotencyKeys(): void
    {
        $lookup = new FakeSyncedProductLinkLookup();
        for ($id = 1; $id <= 247; $id++) {
            $lookup->byIdProduct[$id] = $this->link($id);
        }

        $callCount = 0;
        $sentSizes = [];
        $repo = new FakePackshotJobRepository();
        $client = $this->stubClient(function (SubmitJobRequest $req) use (&$callCount, &$sentSizes): SubmitJobResponse {
            $callCount++;
            $sentSizes[] = count($req->subjects);
            $subjects = [];
            foreach ($req->subjects as $s) {
                $subjects[] = new SubmitJobResponseSubject($s->productRef, ['j-' . $callCount . '-' . $s->productRef]);
            }
            return new SubmitJobResponse('ord-' . $callCount, 'queued', $subjects);
        });
        $submitter = $this->submitter($client, $repo, $lookup);

        $result = $submitter->submit($this->input(range(1, 247), imagesCount: 1));

        self::assertSame(3, $callCount);
        self::assertSame([100, 100, 47], $sentSizes);
        self::assertSame(247, $result->jobsPersisted);
        self::assertCount(3, $result->orderIds);
    }

    public function testPartialChunkFailureReportsBothOutcomes(): void
    {
        $lookup = new FakeSyncedProductLinkLookup();
        for ($id = 1; $id <= 150; $id++) {
            $lookup->byIdProduct[$id] = $this->link($id);
        }

        $callCount = 0;
        $repo = new FakePackshotJobRepository();
        $client = $this->stubClient(function (SubmitJobRequest $req) use (&$callCount): SubmitJobResponse {
            $callCount++;
            if ($callCount === 2) {
                throw new ServerException('upstream burp', 503);
            }
            $subjects = [];
            foreach ($req->subjects as $s) {
                $subjects[] = new SubmitJobResponseSubject($s->productRef, ['j-' . $s->productRef]);
            }
            return new SubmitJobResponse('ord-' . $callCount, 'queued', $subjects);
        });
        $submitter = $this->submitter($client, $repo, $lookup);

        $result = $submitter->submit($this->input(range(1, 150), imagesCount: 1));

        self::assertSame(1, $result->sessionsSubmitted);
        self::assertSame(1, $result->sessionsFailed);
        self::assertSame(100, $result->jobsPersisted);
        self::assertArrayHasKey(2, $result->chunkFailures);
    }

    public function testUnsyncedProductsAreSkippedWithoutSubmitting(): void
    {
        $lookup = new FakeSyncedProductLinkLookup();
        // Both products lookup-able but only one has qamera_asset_id.
        $lookup->byIdProduct[42] = $this->link(42);
        $lookup->byIdProduct[43] = new SyncedProductLink(
            idLink: 200,
            idShop: 1,
            idProduct: 43,
            qameraAssetId: null,
            qameraProductRef: 'ps:1:43',
            displayNameSnapshot: 'Unsynced',
        );

        $client = $this->stubClient(function (SubmitJobRequest $req): SubmitJobResponse {
            self::assertCount(1, $req->subjects, 'unsynced product must be excluded from the request');
            return new SubmitJobResponse('ord-1', 'queued', [
                new SubmitJobResponseSubject('ps:1:42', ['j1']),
            ]);
        });
        $repo = new FakePackshotJobRepository();
        $submitter = $this->submitter($client, $repo, $lookup);

        $result = $submitter->submit($this->input([42, 43], imagesCount: 1));

        self::assertSame(1, $result->jobsPersisted);
    }

    public function testSubjectPackshotAssetIdEqualsLinkAssetId(): void
    {
        $lookup = new FakeSyncedProductLinkLookup();
        // A link whose storage asset id is deliberately distinct from any
        // 'img-*' shape — the outbound Subject MUST carry this exact value.
        $lookup->byIdProduct[42] = new SyncedProductLink(
            idLink: 142,
            idShop: 1,
            idProduct: 42,
            qameraAssetId: 'asset-uuid-xyz',
            qameraProductRef: 'ps:1:42',
            displayNameSnapshot: 'Widget',
            analysisStatus: SyncedProductLink::ANALYSIS_STATUS_DESCRIBED,
        );

        $captured = null;
        $client = $this->stubClient(function (SubmitJobRequest $req) use (&$captured): SubmitJobResponse {
            $captured = $req;
            return new SubmitJobResponse('ord-1', 'queued', [
                new SubmitJobResponseSubject('ps:1:42', ['j1']),
            ]);
        });
        $submitter = $this->submitter($client, new FakePackshotJobRepository(), $lookup);
        $submitter->submit($this->input([42], imagesCount: 1));

        self::assertNotNull($captured);
        self::assertSame('asset-uuid-xyz', $captured->subjects[0]->packshotAssetId);
    }

    public function testEmptyAssetIdLinkIsSkippedByCanGenerate(): void
    {
        $lookup = new FakeSyncedProductLinkLookup();
        // Empty-string asset id (e.g. a malformed/nulled row): canGenerate()
        // must reject it so no empty packshot_asset_id is ever sent upstream.
        $lookup->byIdProduct[42] = new SyncedProductLink(
            idLink: 142,
            idShop: 1,
            idProduct: 42,
            qameraAssetId: '',
            qameraProductRef: 'ps:1:42',
            displayNameSnapshot: 'Widget',
            analysisStatus: SyncedProductLink::ANALYSIS_STATUS_DESCRIBED,
        );

        $client = $this->stubClient(static function (): SubmitJobResponse {
            self::fail('submitJob must not be called when no link can generate');
        });
        $repo = new FakePackshotJobRepository();
        $submitter = $this->submitter($client, $repo, $lookup);

        $result = $submitter->submit($this->input([42], imagesCount: 1));

        self::assertSame(0, $result->sessionsSubmitted);
        self::assertSame(0, $result->jobsPersisted);
        self::assertSame([], $repo->insertedRows);
    }

    public function testPackshotExternalRefMatchesSpecRegex(): void
    {
        $lookup = new FakeSyncedProductLinkLookup();
        $lookup->byIdProduct[42] = $this->link(42);

        $captured = null;
        $client = $this->stubClient(function (SubmitJobRequest $req) use (&$captured): SubmitJobResponse {
            $captured = $req;
            return new SubmitJobResponse('ord-1', 'queued', [
                new SubmitJobResponseSubject('ps:1:42', ['j1']),
            ]);
        });
        $submitter = $this->submitter($client, new FakePackshotJobRepository(), $lookup);
        $submitter->submit($this->input([42], imagesCount: 1));

        self::assertNotNull($captured);
        $subject = $captured->subjects[0];
        self::assertSame(true, $subject->autoRegisterPackshot);
        self::assertMatchesRegularExpression(
            '/^ps:1:42:packshot:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            (string) $subject->packshotExternalRef,
        );
    }

    public function testRegistersInputPackshotBeforeSubmittingJob(): void
    {
        $lookup = new FakeSyncedProductLinkLookup();
        $lookup->byIdProduct[42] = $this->link(42);

        $order = [];
        $registered = [];
        $client = $this->stubClient(
            function (SubmitJobRequest $req) use (&$order): SubmitJobResponse {
                $order[] = 'submit';
                return new SubmitJobResponse('ord-1', 'queued', [
                    new SubmitJobResponseSubject('ps:1:42', ['j1']),
                ]);
            },
            function (RegisterPackshotRequest $req) use (&$order, &$registered): PackshotResponse {
                $order[] = 'register';
                $registered[] = $req;
                return new PackshotResponse($req->externalRef, 'prod-1', 'pack-1', 'created');
            },
        );
        $submitter = $this->submitter($client, new FakePackshotJobRepository(), $lookup);
        $submitter->submit($this->input([42], imagesCount: 1));

        // registration happens, and strictly before the job submit
        self::assertSame(['register', 'submit'], $order);
        self::assertCount(1, $registered);
        $req = $registered[0];
        self::assertSame('ps:1:42:packshot:src', $req->externalRef);
        self::assertSame('ps:1:42', $req->productRef);
        self::assertSame('asset-42', $req->assetId);
        self::assertNull($req->sourceImageRef);
    }

    public function testRegisterPackshotFailureAbortsSubmitWithNoJobAndNoRows(): void
    {
        $lookup = new FakeSyncedProductLinkLookup();
        $lookup->byIdProduct[42] = $this->link(42);

        $repo = new FakePackshotJobRepository();
        $client = $this->stubClient(
            function (SubmitJobRequest $req): SubmitJobResponse {
                self::fail('submitJob must not be called when registerPackshot failed');
            },
            function (RegisterPackshotRequest $req): PackshotResponse {
                throw new ServerException('packshot registration 503');
            },
        );
        $submitter = $this->submitter($client, $repo, $lookup);

        $result = $submitter->submit($this->input([42], imagesCount: 1));

        self::assertSame(0, $result->sessionsSubmitted);
        self::assertSame(1, $result->sessionsFailed);
        self::assertSame([], $repo->insertedRows);
    }

    /**
     * @param int[] $productIds
     */
    private function input(array $productIds, int $imagesCount): SubmitFormInput
    {
        return new SubmitFormInput(
            idShop: 1,
            productIds: $productIds,
            aiModel: 'openai/gpt-image-1',
            aspectRatio: '1:1',
            imagesCount: $imagesCount,
        );
    }

    private function link(int $idProduct, ?string $analysisStatus = SyncedProductLink::ANALYSIS_STATUS_DESCRIBED): SyncedProductLink
    {
        return new SyncedProductLink(
            idLink: 100 + $idProduct,
            idShop: 1,
            idProduct: $idProduct,
            qameraAssetId: 'asset-' . $idProduct,
            qameraProductRef: 'ps:1:' . $idProduct,
            displayNameSnapshot: 'Product ' . $idProduct,
            // Phase 4.4 — submitter still gates on canGenerate(), which
            // now requires `described` in addition to a non-null asset
            // id. Test fixtures default to `described` so existing
            // submitter scenarios stay green; tests that need to verify
            // the new gate pass an explicit override.
            analysisStatus: $analysisStatus,
        );
    }

    /**
     * @param callable(SubmitJobRequest): SubmitJobResponse $handler
     * @param callable(RegisterPackshotRequest): PackshotResponse|null $onRegisterPackshot
     *        Optional hook invoked on each registerPackshot() call (record / throw).
     *        When null, returns a default `created` PackshotResponse without HTTP.
     */
    private function stubClient(callable $handler, ?callable $onRegisterPackshot = null): QameraApiClient
    {
        return new class ($handler, $onRegisterPackshot) extends QameraApiClient {
            /** @var callable(SubmitJobRequest): SubmitJobResponse */
            private $handler;
            /** @var callable(RegisterPackshotRequest): PackshotResponse|null */
            private $onRegisterPackshot;

            public function __construct(callable $handler, ?callable $onRegisterPackshot)
            {
                $this->handler = $handler;
                $this->onRegisterPackshot = $onRegisterPackshot;
                // Bypass parent ctor — never opens an HTTP socket.
            }

            public function registerPackshot(RegisterPackshotRequest $request): PackshotResponse
            {
                if ($this->onRegisterPackshot !== null) {
                    return ($this->onRegisterPackshot)($request);
                }

                return new PackshotResponse($request->externalRef, 'prod-stub', 'pack-stub', 'created');
            }

            public function submitJob(SubmitJobRequest $request): SubmitJobResponse
            {
                return ($this->handler)($request);
            }
        };
    }

    private function submitter(
        QameraApiClient $client,
        FakePackshotJobRepository $repo,
        FakeSyncedProductLinkLookup $lookup,
    ): PackshotJobSubmitter {
        $logger = new class extends PrestaShopLoggerWrapper {
            public function addLog(
                string $message,
                int $severity = 1,
                ?int $errorCode = null,
                ?string $objectType = null,
                ?int $objectId = null,
                bool $allowDuplicate = false
            ): void {
                // Drop logs in tests; assert directly on SubmitResult.
            }
        };
        return new PackshotJobSubmitter($client, $repo, $lookup, $logger);
    }
}
