<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Packshot\Acceptance;

use PHPUnit\Framework\TestCase;
use QameraAi\Module\Packshot\Acceptance\PackshotReviewRepository;
use QameraAi\Module\Packshot\Acceptance\PackshotReviewRow;
use QameraAi\Module\Tests\Support\RecordingDb;
use QameraAi\Module\Webhook\Event\QameraDbException;

final class PackshotReviewRepositoryTest extends TestCase
{
    private RecordingDb $db;
    private PackshotReviewRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new RecordingDb();
        $this->repo = new PackshotReviewRepository($this->db, 'ps_');
    }

    public function testUpsertFromWebhookInsertsPendingAndPreservesVotingOnConflict(): void
    {
        $this->db->affectedRowsScript = [1];

        $this->repo->upsertFromWebhook(new PackshotReviewRow(
            id: null,
            qameraJobId: 'job-1',
            idShop: 1,
            idProduct: 42,
            productRef: 'ps:1:42',
            assetUrl: 'https://cdn.example.com/preview.jpg',
            voting: PackshotReviewRow::VOTING_PENDING,
            votingAt: null,
            generatedAt: '2026-05-29 10:00:00',
        ));

        $sql = $this->db->executed[0];
        self::assertStringStartsWith('INSERT INTO `ps_qamera_packshot_review`', $sql);
        self::assertStringContainsString("'job-1'", $sql);
        self::assertStringContainsString("'pending'", $sql);
        self::assertStringContainsString("'https://cdn.example.com/preview.jpg'", $sql);
        // ON DUPLICATE KEY UPDATE refreshes only the preview + timestamp;
        // it must NOT touch voting/voting_at (an already-graded packshot
        // never reverts to pending on a webhook re-delivery).
        $updatePos = strpos($sql, 'ON DUPLICATE KEY UPDATE');
        self::assertNotFalse($updatePos);
        $updateClause = substr($sql, $updatePos);
        self::assertStringContainsString('`asset_url` = VALUES(`asset_url`)', $updateClause);
        self::assertStringContainsString('`generated_at` = VALUES(`generated_at`)', $updateClause);
        self::assertStringNotContainsString('`voting`', $updateClause);
    }

    public function testUpsertFromWebhookEmitsNullAssetUrlLiteral(): void
    {
        $this->db->affectedRowsScript = [1];

        $this->repo->upsertFromWebhook(new PackshotReviewRow(
            id: null,
            qameraJobId: 'job-2',
            idShop: 1,
            idProduct: 7,
            productRef: 'ps:1:7',
            assetUrl: null,
            voting: PackshotReviewRow::VOTING_PENDING,
            votingAt: null,
            generatedAt: '2026-05-29 10:00:00',
        ));

        $sql = $this->db->executed[0];
        self::assertStringContainsString(', NULL, ', $sql);
        self::assertStringNotContainsString("''", $sql);
    }

    public function testUpsertFromWebhookPropagatesDbFailure(): void
    {
        $this->db->failNextExecute = true;
        $this->expectException(QameraDbException::class);
        $this->repo->upsertFromWebhook(new PackshotReviewRow(
            id: null,
            qameraJobId: 'job-3',
            idShop: 1,
            idProduct: 7,
            productRef: 'ps:1:7',
            assetUrl: null,
            voting: PackshotReviewRow::VOTING_PENDING,
            votingAt: null,
            generatedAt: '2026-05-29 10:00:00',
        ));
    }

    public function testListPendingFiltersOnPendingAndJoinsProductLang(): void
    {
        $this->repo->listPending(2);
        $sql = $this->db->executed[0];

        self::assertStringContainsString('FROM `ps_qamera_packshot_review` r', $sql);
        self::assertStringContainsString('LEFT JOIN `ps_product_lang`', $sql);
        self::assertStringContainsString('`id_lang` = 2', $sql);
        self::assertStringContainsString("r.`voting` = 'pending'", $sql);
        self::assertStringContainsString('ORDER BY r.`generated_at` DESC', $sql);
    }

    public function testSetVotingUpdatesVotingAndTimestamp(): void
    {
        $this->db->affectedRowsScript = [1];

        $this->repo->setVoting('job-1', PackshotReviewRow::VOTING_ACCEPTED, '2026-05-29 11:00:00');

        $sql = $this->db->executed[0];
        self::assertStringStartsWith('UPDATE `ps_qamera_packshot_review`', $sql);
        self::assertStringContainsString("`voting` = 'accepted'", $sql);
        self::assertStringContainsString("`voting_at` = '2026-05-29 11:00:00'", $sql);
        self::assertStringContainsString("WHERE `qamera_job_id` = 'job-1'", $sql);
    }

    public function testSetVotingRejectsUnknownVotingValue(): void
    {
        $this->expectException(QameraDbException::class);
        $this->repo->setVoting('job-1', 'maybe', '2026-05-29 11:00:00');
    }

    public function testHasAcceptedForProductRefTrueWhenRowExists(): void
    {
        $this->db->getRowScript = [['1' => '1']];

        $result = $this->repo->hasAcceptedForProductRef('ps:1:42');

        self::assertTrue($result);
        $sql = $this->db->executed[0];
        self::assertStringContainsString("`product_ref` = 'ps:1:42'", $sql);
        self::assertStringContainsString("`voting` = 'accepted'", $sql);
        self::assertStringContainsString('LIMIT 1', $sql);
    }

    public function testHasAcceptedForProductRefFalseWhenAbsent(): void
    {
        // getRowScript empty → RecordingDb::getRow returns false.
        self::assertFalse($this->repo->hasAcceptedForProductRef('ps:1:99'));
    }

    public function testFindByJobIdHydratesRow(): void
    {
        $this->db->getRowScript = [[
            'id_qamera_packshot_review' => '5',
            'qamera_job_id' => 'job-1',
            'id_shop' => '1',
            'id_product' => '42',
            'product_ref' => 'ps:1:42',
            'asset_url' => 'https://cdn.example.com/preview.jpg',
            'voting' => 'accepted',
            'voting_at' => '2026-05-29 11:00:00',
            'generated_at' => '2026-05-29 10:00:00',
        ]];

        $row = $this->repo->findByJobId('job-1');

        self::assertNotNull($row);
        self::assertSame(5, $row->id);
        self::assertSame('job-1', $row->qameraJobId);
        self::assertSame(42, $row->idProduct);
        self::assertSame('accepted', $row->voting);
        self::assertSame('https://cdn.example.com/preview.jpg', $row->assetUrl);
    }

    public function testFindByJobIdReturnsNullWhenAbsent(): void
    {
        self::assertNull($this->repo->findByJobId('missing'));
    }

    public function testAcceptedRefsInEmitsDistinctInQueryFilteredOnAccepted(): void
    {
        // RecordingDb::executeS returns [] → an empty accepted set; we assert
        // the dedup of inputs and the SQL shape (accepted filter + IN list).
        $result = $this->repo->acceptedRefsIn(['ps:1:42', 'ps:1:7', 'ps:1:42']);

        self::assertSame([], $result);
        $sql = $this->db->executed[0];
        self::assertStringContainsString("`voting` = 'accepted'", $sql);
        self::assertStringContainsString("'ps:1:42'", $sql);
        self::assertStringContainsString("'ps:1:7'", $sql);
        self::assertStringContainsString('DISTINCT `product_ref`', $sql);
    }

    public function testAcceptedRefsInEmptyInputIsNoQuery(): void
    {
        self::assertSame([], $this->repo->acceptedRefsIn([]));
        self::assertSame([], $this->db->executed);
    }
}
