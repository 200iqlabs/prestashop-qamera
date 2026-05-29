<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Packshot;

use PHPUnit\Framework\TestCase;
use QameraAi\Module\Packshot\PackshotJobRepository;
use QameraAi\Module\Packshot\PackshotJobUpdater;
use QameraAi\Module\Packshot\SyncedProductLinkLookup;
use QameraAi\Module\Tests\Support\RecordingDb;
use QameraAi\Module\Tests\Support\SpyLogger;

final class PackshotJobUpdaterTest extends TestCase
{
    private RecordingDb $db;
    private PackshotJobRepository $repository;
    private SyncedProductLinkLookup $lookup;
    private SpyLogger $logger;
    private PackshotJobUpdater $updater;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new RecordingDb();
        $this->repository = new PackshotJobRepository($this->db, 'ps_');
        $this->lookup = new SyncedProductLinkLookup($this->db, 'ps_');
        $this->logger = new SpyLogger();
        $this->updater = new PackshotJobUpdater($this->repository, $this->lookup, $this->logger);
    }

    public function testCompletedEventOnExistingRowIssuesUpdateNotInsert(): void
    {
        $this->db->getRowScript = [$this->fakeRowArray('j1', 'pending')];
        $this->db->affectedRowsScript = [1];

        $this->updater->upsert(
            eventType: 'job.completed',
            deliveryId: 'd1',
            qameraJobId: 'j1',
            outputUrl: 'https://cdn.example.com/out.jpg',
            outputUrlExpiresAt: '2026-06-01 00:00:00',
            lastErrorMessage: null,
            productRef: null,
            orderId: null,
        );

        self::assertCount(2, $this->db->executed);
        self::assertStringContainsString('FROM `ps_qamera_packshot_job`', $this->db->executed[0]);
        self::assertStringStartsWith('SELECT ', $this->db->executed[0]);
        self::assertStringContainsString('UPDATE `ps_qamera_packshot_job`', $this->db->executed[1]);
        self::assertStringContainsString("`status` = 'completed'", $this->db->executed[1]);
        self::assertStringContainsString("'https://cdn.example.com/out.jpg'", $this->db->executed[1]);
    }

    public function testFailedEventRecordsErrorMessage(): void
    {
        $this->db->getRowScript = [$this->fakeRowArray('j1', 'pending')];
        $this->db->affectedRowsScript = [1];

        $this->updater->upsert(
            eventType: 'job.failed',
            deliveryId: 'd1',
            qameraJobId: 'j1',
            outputUrl: null,
            outputUrlExpiresAt: null,
            lastErrorMessage: 'quota exceeded',
            productRef: null,
            orderId: null,
        );

        self::assertStringContainsString("`status` = 'failed'", $this->db->executed[1]);
        self::assertStringContainsString("'quota exceeded'", $this->db->executed[1]);
    }

    public function testRetriedEventMapsToInProgress(): void
    {
        $this->db->getRowScript = [$this->fakeRowArray('j1', 'pending')];
        $this->db->affectedRowsScript = [1];

        $this->updater->upsert(
            eventType: 'job.retried',
            deliveryId: 'd1',
            qameraJobId: 'j1',
            outputUrl: null,
            outputUrlExpiresAt: null,
            lastErrorMessage: null,
            productRef: null,
            orderId: null,
        );

        self::assertStringContainsString("`status` = 'in_progress'", $this->db->executed[1]);
    }

    public function testUnknownEventTypeMapsToPendingWithWarning(): void
    {
        $this->db->getRowScript = [$this->fakeRowArray('j1', 'pending')];
        $this->db->affectedRowsScript = [1];

        $this->updater->upsert(
            eventType: 'job.paused',
            deliveryId: 'd1',
            qameraJobId: 'j1',
            outputUrl: null,
            outputUrlExpiresAt: null,
            lastErrorMessage: null,
            productRef: null,
            orderId: null,
        );

        self::assertStringContainsString("`status` = 'pending'", $this->db->executed[1]);
        $warnings = $this->logger->entriesAtLevel('warning');
        self::assertNotEmpty($warnings);
        self::assertSame('unknown_payload_event_type_mapped_to_pending', $warnings[0]['message']);
    }

    public function testPreSubmitRaceInsertsWhenRowMissingAndFkRecovered(): void
    {
        // First probe (findByJobId) → no row. Second probe (findIdLink) →
        // returns id_link=99. Then the INSERT happens.
        $this->db->getRowScript = [
            false, // findByJobId miss
            ['id_link' => 99], // findIdLink hit
        ];
        $this->db->affectedRowsScript = [1];

        $this->updater->upsert(
            eventType: 'job.completed',
            deliveryId: 'd1',
            qameraJobId: 'j1',
            outputUrl: 'https://cdn.example.com/out.jpg',
            outputUrlExpiresAt: '2026-06-01 00:00:00',
            lastErrorMessage: null,
            productRef: 'ps:1:42',
            orderId: 'ord-123',
        );

        self::assertCount(3, $this->db->executed);
        self::assertStringContainsString('INSERT INTO `ps_qamera_packshot_job`', $this->db->executed[2]);
        self::assertStringContainsString("'ord-123'", $this->db->executed[2]);

        $info = $this->logger->entriesAtLevel('info');
        $messages = array_column($info, 'message');
        self::assertContains('pre_submit_webhook_upsert', $messages);
    }

    public function testMissingProductRefLogsAndNoOps(): void
    {
        // Row not found AND no product_ref → log INFO + noop.
        $this->db->getRowScript = [false];

        $this->updater->upsert(
            eventType: 'job.completed',
            deliveryId: 'd1',
            qameraJobId: 'j1',
            outputUrl: null,
            outputUrlExpiresAt: null,
            lastErrorMessage: null,
            productRef: null,
            orderId: null,
        );

        self::assertCount(1, $this->db->executed);
        $info = $this->logger->entriesAtLevel('info');
        $messages = array_column($info, 'message');
        self::assertContains('webhook_skipped_no_recoverable_fk', $messages);
    }

    public function testMalformedProductRefLogsWarningAndNoOps(): void
    {
        $this->db->getRowScript = [false];

        $this->updater->upsert(
            eventType: 'job.completed',
            deliveryId: 'd1',
            qameraJobId: 'j1',
            outputUrl: null,
            outputUrlExpiresAt: null,
            lastErrorMessage: null,
            productRef: 'totally-not-a-ref',
            orderId: 'ord-1',
        );

        self::assertCount(1, $this->db->executed);
        $warnings = $this->logger->entriesAtLevel('warning');
        $messages = array_column($warnings, 'message');
        self::assertContains('webhook_malformed_product_ref', $messages);
    }

    /**
     * @return array<string, mixed>
     */
    private function fakeRowArray(string $jobId, string $status): array
    {
        return [
            'id_qamera_packshot_job' => 1,
            'qamera_job_id' => $jobId,
            'qamera_order_id' => 'ord-X',
            'id_qamera_product_link' => 99,
            'id_shop' => 1,
            'id_product' => 42,
            'packshot_external_ref' => 'ps:1:42:packshot:00000000-0000-0000-0000-000000000000',
            'status' => $status,
            'output_url' => null,
            'output_url_expires_at' => null,
            'last_error_message' => null,
            'ai_model' => 'openai/gpt-image-1',
            'aspect_ratio' => '1:1',
            'images_count' => 4,
            'session_config_json' => '{}',
            'submitted_at' => '2026-05-28 12:00:00',
            'last_synced_at' => null,
        ];
    }
}
