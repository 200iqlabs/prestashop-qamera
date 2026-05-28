<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Packshot;

use PHPUnit\Framework\TestCase;
use QameraAi\Module\Packshot\JobsGridFilters;
use QameraAi\Module\Packshot\PackshotJobRepository;
use QameraAi\Module\Packshot\PackshotJobRow;
use QameraAi\Module\Packshot\PackshotJobWebhookUpdate;
use QameraAi\Module\Tests\Support\RecordingDb;
use QameraAi\Module\Webhook\Event\QameraDbException;

final class PackshotJobRepositoryTest extends TestCase
{
    private RecordingDb $db;
    private PackshotJobRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new RecordingDb();
        $this->repo = new PackshotJobRepository($this->db, 'ps_');
    }

    public function testInsertBatchEmitsSingleMultiRowStatement(): void
    {
        $this->db->affectedRowsScript = [3];
        $rows = [
            $this->row('j1'),
            $this->row('j2'),
            $this->row('j3'),
        ];

        $this->repo->insertBatch($rows);

        self::assertCount(1, $this->db->executed);
        $sql = $this->db->executed[0];
        self::assertStringContainsString('INSERT INTO `ps_qamera_packshot_job`', $sql);
        // All three job_ids land in one VALUES tuple list.
        self::assertStringContainsString("'j1'", $sql);
        self::assertStringContainsString("'j2'", $sql);
        self::assertStringContainsString("'j3'", $sql);
        // ON DUPLICATE KEY UPDATE clause must not touch submitted_at
        // (already-submitted rows keep their original timestamp).
        $updatePos = strpos($sql, 'ON DUPLICATE KEY UPDATE');
        self::assertNotFalse($updatePos);
        self::assertStringNotContainsString('`submitted_at`', substr($sql, $updatePos));
        // status is owned by webhook updates, NOT the submitter retry — so
        // ON DUPLICATE KEY UPDATE must leave status alone on idempotent
        // re-insert (otherwise a webhook'd completed row would revert to
        // pending if the operator clicked Submit again).
        self::assertStringNotContainsString('`status`', substr($sql, $updatePos));
    }

    public function testInsertBatchEmptyRowsIsNoop(): void
    {
        $this->repo->insertBatch([]);
        self::assertSame([], $this->db->executed);
    }

    public function testInsertBatchPropagatesDbFailure(): void
    {
        $this->db->failNextExecute = true;
        $this->expectException(QameraDbException::class);
        $this->repo->insertBatch([$this->row('j1')]);
    }

    public function testUpsertFromWebhookUpdateOnlyPathWhenFallbacksMissing(): void
    {
        $this->db->affectedRowsScript = [1];
        $update = new PackshotJobWebhookUpdate(
            qameraJobId: 'j1',
            status: 'completed',
            outputUrl: 'https://cdn.example.com/out.jpg',
            outputUrlExpiresAt: '2026-06-01 00:00:00',
            lastErrorMessage: null,
            now: '2026-05-28 12:00:00',
        );

        $this->repo->upsertFromWebhook($update);

        $sql = $this->db->executed[0];
        self::assertStringStartsWith('UPDATE `ps_qamera_packshot_job`', $sql);
        self::assertStringContainsString("WHERE `qamera_job_id` = 'j1'", $sql);
    }

    public function testUpsertFromWebhookInsertPathWhenAllFallbacksPresent(): void
    {
        $this->db->affectedRowsScript = [1];
        $update = new PackshotJobWebhookUpdate(
            qameraJobId: 'j1',
            status: 'completed',
            outputUrl: 'https://cdn.example.com/out.jpg',
            outputUrlExpiresAt: '2026-06-01 00:00:00',
            lastErrorMessage: null,
            now: '2026-05-28 12:00:00',
            fallbackQameraOrderId: 'ord-1',
            fallbackIdQameraProductLink: 99,
            fallbackIdShop: 1,
            fallbackIdProduct: 42,
            fallbackPackshotExternalRef: 'ps:1:42:packshot:abc',
            fallbackAiModel: '(unknown)',
            fallbackAspectRatio: '1:1',
            fallbackImagesCount: 1,
            fallbackSessionConfig: ['_recovered_via' => 'webhook_pre_submit_race'],
        );

        $this->repo->upsertFromWebhook($update);

        $sql = $this->db->executed[0];
        self::assertStringStartsWith('INSERT INTO `ps_qamera_packshot_job`', $sql);
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE', $sql);
    }

    public function testListForGridIncludesStatusFilterAndJoin(): void
    {
        $this->repo->listForGrid(new JobsGridFilters('failed', 1));
        $sql = $this->db->executed[0];

        self::assertStringContainsString('FROM `ps_qamera_packshot_job` j', $sql);
        self::assertStringContainsString('LEFT JOIN `ps_product_lang`', $sql);
        self::assertStringContainsString("AND j.`status` = 'failed'", $sql);
        self::assertStringContainsString('ORDER BY j.`submitted_at` DESC', $sql);
    }

    private function row(string $jobId): PackshotJobRow
    {
        return new PackshotJobRow(
            id: null,
            qameraJobId: $jobId,
            qameraOrderId: 'ord-1',
            idQameraProductLink: 99,
            idShop: 1,
            idProduct: 42,
            packshotExternalRef: 'ps:1:42:packshot:11111111-2222-3333-4444-555555555555',
            status: PackshotJobRow::STATUS_PENDING,
            outputUrl: null,
            outputUrlExpiresAt: null,
            lastErrorMessage: null,
            aiModel: 'openai/gpt-image-1',
            aspectRatio: '1:1',
            imagesCount: 4,
            sessionConfig: ['key' => 'val'],
            submittedAt: '2026-05-28 12:00:00',
            lastSyncedAt: null,
        );
    }
}
