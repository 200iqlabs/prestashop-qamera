<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Webhook\Event;

use PHPUnit\Framework\TestCase;
use QameraAi\Module\Tests\Support\RecordingDb;
use QameraAi\Module\Webhook\Event\PackshotLinkUpdater;
use QameraAi\Module\Webhook\Event\QameraDbException;

final class PackshotLinkUpdaterTest extends TestCase
{
    private RecordingDb $db;
    private PackshotLinkUpdater $updater;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new RecordingDb();
        $this->updater = new PackshotLinkUpdater($this->db, 'ps_');
    }

    public function testInsertPathReturnsTrue(): void
    {
        $this->db->affectedRowsScript = [1];

        $result = $this->updater->upsertByPackshotId($this->sampleRow());

        self::assertTrue($result);
        self::assertCount(1, $this->db->executed);
        self::assertStringContainsString('INSERT INTO `ps_qamera_packshot_link`', $this->db->executed[0]);
    }

    public function testUpdatePathReturnsFalse(): void
    {
        // MySQL reports 2 for an `ON DUPLICATE KEY UPDATE` that actually
        // changed at least one column. Treat as "updated existing row".
        $this->db->affectedRowsScript = [2];

        $result = $this->updater->upsertByPackshotId($this->sampleRow());

        self::assertFalse($result);
    }

    public function testNoopUpdatePathReturnsFalse(): void
    {
        // ON DUPLICATE KEY UPDATE matched but changed nothing — also "not
        // an insert".
        $this->db->affectedRowsScript = [0];

        self::assertFalse($this->updater->upsertByPackshotId($this->sampleRow()));
    }

    public function testDbFailureThrowsQameraDbException(): void
    {
        $this->db->failNextExecute = true;

        $this->expectException(QameraDbException::class);
        $this->updater->upsertByPackshotId($this->sampleRow());
    }

    public function testOnDuplicateKeyUpdateClauseOmitsImmutableColumns(): void
    {
        $this->db->affectedRowsScript = [1];
        $this->updater->upsertByPackshotId($this->sampleRow());

        $sql = $this->db->executed[0];
        // Extract just the ON DUPLICATE KEY UPDATE clause to assert immutables
        // are absent from the SET list (the INSERT column list mentions them,
        // which is correct — the UPDATE clause must not).
        $pos = strpos($sql, 'ON DUPLICATE KEY UPDATE');
        self::assertNotFalse($pos);
        $updateClause = substr($sql, $pos);

        self::assertStringNotContainsString('`id_shop`', $updateClause);
        self::assertStringNotContainsString('`id_product`', $updateClause);
        self::assertStringNotContainsString('`qamera_packshot_ref`', $updateClause);
        self::assertStringNotContainsString('`created_at`', $updateClause);
    }

    public function testNullableJobIdAndErrorMessageRenderAsSqlNull(): void
    {
        $this->db->affectedRowsScript = [1];
        $row = $this->sampleRow();
        $row['qamera_job_id'] = null;
        $row['last_error_message'] = null;

        $this->updater->upsertByPackshotId($row);

        $sql = $this->db->executed[0];
        // Both columns appear in the VALUES clause as the literal token
        // `NULL`, not as the string `'NULL'`. A naive `'%s'` rendering
        // would silently cast NULL → empty string and persist `''`.
        self::assertMatchesRegularExpression('/VALUES \([^)]*NULL[^)]*NULL/', $sql);
    }

    /**
     * @return array{
     *     qamera_packshot_id: string,
     *     qamera_packshot_ref: string,
     *     qamera_job_id: ?string,
     *     id_shop: int,
     *     id_product: int,
     *     status: string,
     *     last_error_message: ?string,
     *     now: string
     * }
     */
    private function sampleRow(): array
    {
        return [
            'qamera_packshot_id' => 'packshot-uuid',
            'qamera_packshot_ref' => 'ps:1:42:packshot:packshot-uuid',
            'qamera_job_id' => 'job-uuid',
            'id_shop' => 1,
            'id_product' => 42,
            'status' => 'ready',
            'last_error_message' => null,
            'now' => '2026-05-28 12:00:00',
        ];
    }
}
