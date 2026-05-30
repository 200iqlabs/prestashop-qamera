<?php

declare(strict_types=1);

namespace QameraAi\Module\Packshot\Output;

use Db;
use QameraAi\Module\Webhook\Event\QameraDbException;

/**
 * Persistence + read-side queries for `ps_qamera_imported_output`
 * (add-packshot-output-downloader, D4). One ledger row per imported output;
 * the table triples as dedup ledger (import-once), partial-retry cursor
 * ({@see importedIndexes}), and Qamera-origin marker ({@see isImageImported},
 * consulted by ProductImageSyncService to skip re-upload).
 *
 * Statement strategy mirrors {@see \QameraAi\Module\Packshot\Acceptance\PackshotReviewRepository}:
 * raw SQL with `Db::escape()` for strings and integer casts for numerics.
 */
class ImportedOutputRepository
{
    private const SELECT_COLUMNS = '`id_qamera_imported_output`, `qamera_job_id`, '
        . '`output_index`, `output_type`, `id_shop`, `id_product`, '
        . '`id_image`, `imported_at`';

    public function __construct(
        private readonly Db $db,
        private readonly string $tablePrefix,
    ) {
    }

    /**
     * Record an imported output. Idempotent on the `(qamera_job_id,
     * output_index)` UNIQUE key via INSERT IGNORE — a second record for the
     * same output is a silent no-op (import-once), while a genuine DB error
     * still surfaces as a thrown exception.
     *
     * @throws QameraDbException on DB error
     */
    public function record(ImportedOutputRow $row): void
    {
        $idImageSql = $row->idImage !== null ? (string) (int) $row->idImage : 'NULL';

        $sql = sprintf(
            'INSERT IGNORE INTO `%sqamera_imported_output` '
            . '(`qamera_job_id`, `output_index`, `output_type`, `id_shop`, '
            . '`id_product`, `id_image`, `imported_at`) '
            . "VALUES ('%s', %d, '%s', %d, %d, %s, '%s')",
            $this->tablePrefix,
            $this->escape($row->qameraJobId),
            $row->outputIndex,
            $this->escape($row->outputType),
            $row->idShop,
            $row->idProduct,
            $idImageSql,
            $this->escape($row->importedAt)
        );

        if (!$this->db->execute($sql)) {
            throw new QameraDbException('imported_output record failed');
        }
    }

    /**
     * The output indexes already imported for a job — the partial-retry
     * cursor (the importer imports only outputs NOT in this set).
     *
     * @return int[]
     *
     * @throws QameraDbException on DB error
     */
    public function importedIndexes(string $qameraJobId): array
    {
        $sql = sprintf(
            'SELECT `output_index` FROM `%sqamera_imported_output` '
            . "WHERE `qamera_job_id` = '%s'",
            $this->tablePrefix,
            $this->escape($qameraJobId)
        );

        $rows = $this->db->executeS($sql);
        if ($rows === false) {
            throw new QameraDbException('imported_output importedIndexes failed');
        }
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (isset($row['output_index'])) {
                $out[] = (int) $row['output_index'];
            }
        }
        return $out;
    }

    /**
     * Origin marker: true iff `id_image` was written into the gallery by the
     * output-import flow. The sync layer consults this to skip re-uploading a
     * Qamera-generated image back to Qamera (the feedback-loop guard).
     *
     * @throws QameraDbException on DB error
     */
    public function isImageImported(int $idImage): bool
    {
        if ($idImage <= 0) {
            return false;
        }

        // NOTE: Db::getRow() auto-appends `LIMIT 1`; no explicit LIMIT here or
        // `LIMIT 1 LIMIT 1` is a MySQL syntax error (same pitfall guarded in
        // PackshotReviewRepository::hasAcceptedForProductRef).
        $sql = sprintf(
            'SELECT 1 AS `n` FROM `%sqamera_imported_output` '
            . 'WHERE `id_image` = %d',
            $this->tablePrefix,
            $idImage
        );

        $row = $this->db->getRow($sql);
        return is_array($row) && $row !== [];
    }

    /**
     * All ledger rows for a job (every imported output), hydrated to VOs.
     *
     * @return ImportedOutputRow[]
     *
     * @throws QameraDbException on DB error
     */
    public function findByJob(string $qameraJobId): array
    {
        $sql = sprintf(
            'SELECT %s FROM `%sqamera_imported_output` '
            . "WHERE `qamera_job_id` = '%s' ORDER BY `output_index` ASC",
            self::SELECT_COLUMNS,
            $this->tablePrefix,
            $this->escape($qameraJobId)
        );

        $rows = $this->db->executeS($sql);
        if ($rows === false) {
            throw new QameraDbException('imported_output findByJob failed');
        }
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->hydrate($row);
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ImportedOutputRow
    {
        return new ImportedOutputRow(
            isset($row['id_qamera_imported_output']) ? (int) $row['id_qamera_imported_output'] : null,
            (string) $row['qamera_job_id'],
            (int) $row['output_index'],
            (string) $row['output_type'],
            (int) $row['id_shop'],
            (int) $row['id_product'],
            isset($row['id_image']) && $row['id_image'] !== null && $row['id_image'] !== ''
                ? (int) $row['id_image']
                : null,
            (string) $row['imported_at'],
        );
    }

    private function escape(string $value): string
    {
        return $this->db->escape($value, true, true);
    }
}
