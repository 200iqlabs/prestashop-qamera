<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Controller\Admin;

use PHPUnit\Framework\TestCase;
use QameraAi\Module\Controller\Admin\JobStatusController;
use QameraAi\Module\Packshot\Acceptance\PackshotReviewRepository;
use QameraAi\Module\Packshot\JobRefreshResult;
use QameraAi\Module\Packshot\JobsStatusRefresher;
use QameraAi\Module\Packshot\Output\ImportedOutputRepository;
use QameraAi\Module\Packshot\Output\OutputImporter;
use QameraAi\Module\Packshot\PackshotJobRepository;
use QameraAi\Module\Packshot\PackshotJobRow;
use Symfony\Component\HttpFoundation\Request;

/**
 * Guards the poll wiring added in fix-jobs-history-import-refresh: statusAction
 * must surface `import_state` (OutputImporter::gridState) so the JS can render
 * the "Download to shop" affordance in place. gridState's own state matrix is
 * covered in {@see \QameraAi\Module\Tests\Unit\Packshot\Output\OutputImporterTest}.
 */
final class JobStatusControllerTest extends TestCase
{
    public function testStatusPayloadCarriesImportStateFromGridState(): void
    {
        $row = $this->jobRow('completed');

        $repository = $this->createMock(PackshotJobRepository::class);
        $repository->method('findByJobId')->with('job-1')->willReturn($row);

        $refresher = $this->createMock(JobsStatusRefresher::class);
        $refresher->method('refresh')->willReturn(new JobRefreshResult(
            'completed',
            'https://cdn.example/out.png',
            null,
            null,
            null,
        ));
        $refresher->method('isInFlight')->willReturn(false);

        $reviews = $this->createMock(PackshotReviewRepository::class);
        $reviews->method('findByJobId')->with('job-1')->willReturn(null);

        $importedOutputs = $this->createMock(ImportedOutputRepository::class);
        $importedOutputs->method('importedIndexes')->with('job-1')->willReturn([]);

        // The controller must hand the freshly-refreshed status + the review +
        // the ledger indexes straight to gridState, and ship its result verbatim.
        $importer = $this->createMock(OutputImporter::class);
        $importer->expects(self::once())
            ->method('gridState')
            ->with('completed', null, [])
            ->willReturn(['state' => 'active']);

        $controller = $this->controller();
        $response = $controller->statusAction(
            new Request(),
            'job-1',
            $repository,
            $refresher,
            $importer,
            $importedOutputs,
            $reviews,
        );

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertArrayHasKey('import_state', $payload);
        self::assertSame(['state' => 'active'], $payload['import_state']);
    }

    public function testNotFoundShortCircuitsBeforeImportState(): void
    {
        $repository = $this->createMock(PackshotJobRepository::class);
        $repository->method('findByJobId')->willReturn(null);

        $refresher = $this->createMock(JobsStatusRefresher::class);
        $reviews = $this->createMock(PackshotReviewRepository::class);
        $importedOutputs = $this->createMock(ImportedOutputRepository::class);

        $importer = $this->createMock(OutputImporter::class);
        $importer->expects(self::never())->method('gridState');

        $response = $this->controller()->statusAction(
            new Request(),
            'missing',
            $repository,
            $refresher,
            $importer,
            $importedOutputs,
            $reviews,
        );

        self::assertSame(404, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertArrayNotHasKey('import_state', $payload);
    }

    /**
     * The controller is `final`, so it can't be doubled. In the unit suite its
     * base class is the stub from tests/Stubs/SymfonyControllerStubs.php, whose
     * no-arg constructor and identity trans() let us instantiate it directly.
     */
    private function controller(): JobStatusController
    {
        return new JobStatusController();
    }

    private function jobRow(string $status): PackshotJobRow
    {
        return new PackshotJobRow(
            1,
            'job-1',
            'order-1',
            10,
            1,
            33,
            'ref-1',
            $status,
            null,
            null,
            null,
            'model',
            '1:1',
            1,
            [],
            '2026-05-30 00:00:00',
            null,
        );
    }
}
