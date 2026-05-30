<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Packshot\Output;

use PHPUnit\Framework\TestCase;
use QameraAi\Module\Packshot\Output\ImportedOutputRepository;
use QameraAi\Module\Packshot\Output\ImportedOutputRow;
use QameraAi\Module\Tests\Support\RecordingDb;
use QameraAi\Module\Webhook\Event\QameraDbException;

final class ImportedOutputRepositoryTest extends TestCase
{
    private RecordingDb $db;
    private ImportedOutputRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new RecordingDb();
        $this->repo = new ImportedOutputRepository($this->db, 'ps_');
    }

    public function testRecordInsertsImageRowIgnoringDuplicates(): void
    {
        $this->db->affectedRowsScript = [1];

        $this->repo->record(new ImportedOutputRow(
            id: null,
            qameraJobId: 'job-1',
            outputIndex: 0,
            outputType: 'image/jpeg',
            idShop: 1,
            idProduct: 42,
            idImage: 200,
            importedAt: '2026-05-30 10:00:00',
        ));

        $sql = $this->db->executed[0];
        self::assertStringStartsWith('INSERT IGNORE INTO `ps_qamera_imported_output`', $sql);
        self::assertStringContainsString("'job-1'", $sql);
        self::assertStringContainsString("'image/jpeg'", $sql);
        self::assertStringContainsString("'2026-05-30 10:00:00'", $sql);
        // id_image present → integer literal, not quoted.
        self::assertMatchesRegularExpression('/,\s*200,/', $sql);
    }

    public function testRecordEmitsNullIdImageForNonPlacedOutput(): void
    {
        $this->db->affectedRowsScript = [1];

        $this->repo->record(new ImportedOutputRow(
            id: null,
            qameraJobId: 'job-vid',
            outputIndex: 2,
            outputType: 'video/mp4',
            idShop: 1,
            idProduct: 7,
            idImage: null,
            importedAt: '2026-05-30 10:00:00',
        ));

        $sql = $this->db->executed[0];
        self::assertStringContainsString('video/mp4', $sql);
        self::assertStringContainsString(', NULL, ', $sql);
    }

    public function testRecordPropagatesDbFailure(): void
    {
        $this->db->failNextExecute = true;
        $this->expectException(QameraDbException::class);
        $this->repo->record(new ImportedOutputRow(
            id: null,
            qameraJobId: 'job-3',
            outputIndex: 0,
            outputType: 'image/jpeg',
            idShop: 1,
            idProduct: 7,
            idImage: 1,
            importedAt: '2026-05-30 10:00:00',
        ));
    }

    public function testImportedIndexesReturnsIntListForJob(): void
    {
        $this->db->executeSScript = [[
            ['output_index' => '0'],
            ['output_index' => '2'],
        ]];

        $indexes = $this->repo->importedIndexes('job-1');

        self::assertSame([0, 2], $indexes);
        $sql = $this->db->executed[0];
        self::assertStringContainsString('FROM `ps_qamera_imported_output`', $sql);
        self::assertStringContainsString("`qamera_job_id` = 'job-1'", $sql);
    }

    public function testImportedIndexesEmptyWhenNone(): void
    {
        self::assertSame([], $this->repo->importedIndexes('job-x'));
    }

    public function testIsImageImportedTrueWhenRowExists(): void
    {
        $this->db->getRowScript = [['n' => '1']];

        self::assertTrue($this->repo->isImageImported(200));

        $sql = $this->db->executed[0];
        self::assertStringContainsString('`id_image` = 200', $sql);
        // Must NOT carry an explicit LIMIT — Db::getRow() appends its own.
        self::assertStringNotContainsString('LIMIT', $sql);
    }

    public function testIsImageImportedFalseWhenAbsent(): void
    {
        self::assertFalse($this->repo->isImageImported(999));
    }

    public function testIsImageImportedFalseForNonPositiveId(): void
    {
        // A null/0 id_image (video rows) must never match; guard before querying.
        self::assertFalse($this->repo->isImageImported(0));
        self::assertSame([], $this->db->executed);
    }

    public function testFindByJobHydratesRows(): void
    {
        $this->db->executeSScript = [[
            [
                'id_qamera_imported_output' => '5',
                'qamera_job_id' => 'job-1',
                'output_index' => '0',
                'output_type' => 'image/jpeg',
                'id_shop' => '1',
                'id_product' => '42',
                'id_image' => '200',
                'imported_at' => '2026-05-30 10:00:00',
            ],
        ]];

        $rows = $this->repo->findByJob('job-1');

        self::assertCount(1, $rows);
        self::assertSame(5, $rows[0]->id);
        self::assertSame(0, $rows[0]->outputIndex);
        self::assertSame(200, $rows[0]->idImage);
        self::assertSame('image/jpeg', $rows[0]->outputType);
    }

    public function testFindByJobNullIdImageHydratesNull(): void
    {
        $this->db->executeSScript = [[
            [
                'id_qamera_imported_output' => '6',
                'qamera_job_id' => 'job-vid',
                'output_index' => '2',
                'output_type' => 'video/mp4',
                'id_shop' => '1',
                'id_product' => '7',
                'id_image' => null,
                'imported_at' => '2026-05-30 10:00:00',
            ],
        ]];

        $rows = $this->repo->findByJob('job-vid');

        self::assertCount(1, $rows);
        self::assertNull($rows[0]->idImage);
    }
}
