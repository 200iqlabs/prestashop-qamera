<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Packshot\Output;

use PHPUnit\Framework\TestCase;
use QameraAi\Module\Packshot\Output\ImportResult;
use QameraAi\Module\Packshot\Output\ImportResultPresenter;

final class ImportResultPresenterTest extends TestCase
{
    private ImportResultPresenter $presenter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->presenter = new ImportResultPresenter();
    }

    public function testFullSuccess(): void
    {
        $out = $this->presenter->present(new ImportResult(
            [['output_index' => 0, 'id_image' => 200]],
            [],
            [],
            [],
            null,
        ));

        self::assertSame(200, $out['status']);
        self::assertTrue($out['json']['ok']);
        self::assertSame('imported', $out['json']['state']);
        self::assertSame([['output_index' => 0, 'id_image' => 200]], $out['json']['imported']);
    }

    public function testPartialWhenSomeOutputsFailed(): void
    {
        $out = $this->presenter->present(new ImportResult(
            [['output_index' => 1, 'id_image' => 201]],
            [],
            [],
            [['output_index' => 0, 'error' => 'download failed']],
            null,
        ));

        self::assertSame(200, $out['status']);
        self::assertFalse($out['json']['ok']);
        self::assertSame('partial', $out['json']['state']);
        self::assertCount(1, $out['json']['failures']);
    }

    public function testAlreadyImported(): void
    {
        $out = $this->presenter->present(new ImportResult([], [0], [], [], null));

        self::assertSame(200, $out['status']);
        self::assertSame('already_imported', $out['json']['state']);
    }

    public function testOnlyNonImageRecorded(): void
    {
        $out = $this->presenter->present(new ImportResult([], [], [0], [], null));

        self::assertSame(200, $out['status']);
        self::assertSame('nothing', $out['json']['state']);
        self::assertSame([0], $out['json']['recorded_non_image']);
    }

    /**
     * @dataProvider abortReasons
     */
    public function testAbortReasonMapsToStatus(string $reason, int $expectedStatus): void
    {
        $out = $this->presenter->present(ImportResult::aborted($reason));

        self::assertSame($expectedStatus, $out['status']);
        self::assertFalse($out['json']['ok']);
        self::assertSame('aborted', $out['json']['state']);
        self::assertSame($reason, $out['json']['reason']);
    }

    /**
     * @return array<string, array{0:string, 1:int}>
     */
    public static function abortReasons(): array
    {
        return [
            'not completed' => ['not_completed', 409],
            'packshot not accepted' => ['packshot_not_accepted', 409],
            'invalid product ref' => ['invalid_product_ref', 422],
            'product not registered' => ['product_not_registered', 404],
            'api error' => ['api_error', 502],
            'unknown' => ['something_else', 400],
        ];
    }
}
