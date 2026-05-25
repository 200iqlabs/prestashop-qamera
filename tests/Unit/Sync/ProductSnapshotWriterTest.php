<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Sync;

use Configuration;
use Db;
use PHPUnit\Framework\TestCase;
use PrestaShopDatabaseException;
use PrestaShopLogger;
use Product;
use QameraAi\Module\Sync\PrestaShopLoggerWrapper;
use QameraAi\Module\Sync\ProductRefBuilder;
use QameraAi\Module\Sync\ProductSnapshotWriter;

final class ProductSnapshotWriterTest extends TestCase
{
    private const PREFIX = 'ps_';

    /** @var Db&\PHPUnit\Framework\MockObject\MockObject */
    private $db;

    /** @var PrestaShopLoggerWrapper&\PHPUnit\Framework\MockObject\MockObject */
    private $logger;

    private ProductSnapshotWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();
        Configuration::$values = ['PS_LANG_DEFAULT:1' => 2];
        PrestaShopLogger::$logs = [];

        $this->db = $this->getMockBuilder(Db::class)
            ->onlyMethods(['execute'])
            ->getMockForAbstractClass();

        $this->logger = $this->createMock(PrestaShopLoggerWrapper::class);

        $this->writer = new ProductSnapshotWriter(
            $this->db,
            self::PREFIX,
            new ProductRefBuilder(),
            $this->logger
        );
    }

    public function testInsertEmitsUpsertSqlWithPendingStatus(): void
    {
        $product = $this->productFixture(42, ['Widget'], 'WDG-001', ['Short desc']);

        $captured = '';
        $this->db->expects(self::once())
            ->method('execute')
            ->willReturnCallback(static function (string $sql) use (&$captured): bool {
                $captured = $sql;
                return true;
            });

        $this->writer->upsertFromProduct($product, 1);

        self::assertStringContainsString('ps_qamera_product_link', $captured);
        self::assertStringContainsString("'pending'", $captured);
        self::assertStringContainsString('NULL', $captured);
        self::assertStringContainsString("'ps:1:42'", $captured);
        self::assertStringContainsString("'Widget'", $captured);
        self::assertStringContainsString("'WDG-001'", $captured);
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE', $captured);
        // Upsert clause must NOT touch state owned by downstream sync.
        $upsertClause = substr($captured, (int) strpos($captured, 'ON DUPLICATE KEY UPDATE'));
        self::assertStringNotContainsString('`status`', $upsertClause);
        self::assertStringNotContainsString('`qamera_product_id`', $upsertClause);
        self::assertStringNotContainsString('`qamera_product_ref`', $upsertClause);
        self::assertStringNotContainsString('`last_error_message`', $upsertClause);
        self::assertStringNotContainsString('`last_synced_at`', $upsertClause);
        self::assertStringNotContainsString('`created_at`', $upsertClause);
    }

    public function testDbFailureBubblesThrowable(): void
    {
        $product = $this->productFixture(42, ['Widget'], 'WDG-001', ['Short']);

        $this->db->method('execute')->willThrowException(new PrestaShopDatabaseException('boom'));

        $this->expectException(PrestaShopDatabaseException::class);
        $this->writer->upsertFromProduct($product, 1);
    }

    public function testDefaultLanguageIsUsedForNameSnapshot(): void
    {
        $product = $this->productFixture(7, [1 => 'Widget', 2 => "Wid\u{017C}et"], 'WDG', [1 => 'EN', 2 => 'PL']);

        $captured = '';
        $this->db->method('execute')->willReturnCallback(static function (string $sql) use (&$captured): bool {
            $captured = $sql;
            return true;
        });

        $this->writer->upsertFromProduct($product, 1);

        self::assertStringContainsString("'Wid\u{017C}et'", $captured);
        self::assertStringNotContainsString("'Widget'", $captured);
        self::assertStringContainsString("'PL'", $captured);
    }

    public function testDefaultLanguageFallbackLogsWarning(): void
    {
        $product = $this->productFixture(7, [1 => 'Widget'], 'WDG', [1 => 'desc']);

        $messages = [];
        $this->logger->method('addLog')->willReturnCallback(
            static function (string $msg) use (&$messages): void {
                $messages[] = $msg;
            }
        );

        $captured = '';
        $this->db->method('execute')->willReturnCallback(static function (string $sql) use (&$captured): bool {
            $captured = $sql;
            return true;
        });

        $this->writer->upsertFromProduct($product, 1);

        self::assertStringContainsString("'Widget'", $captured);
        $merged = implode("\n", $messages);
        self::assertStringContainsString('missing in default lang', $merged);
        self::assertStringContainsString('field=name', $merged);
    }

    public function testDescriptionTruncatedAt5000Chars(): void
    {
        $long = str_repeat('a', 6000);
        $product = $this->productFixture(11, ['name'], 'ref', [$long]);

        $captured = '';
        $this->db->method('execute')->willReturnCallback(static function (string $sql) use (&$captured): bool {
            $captured = $sql;
            return true;
        });

        $this->writer->upsertFromProduct($product, 1);

        self::assertStringContainsString("'" . str_repeat('a', 5000) . "'", $captured);
        self::assertStringNotContainsString(str_repeat('a', 5001), $captured);
    }

    public function testEmptyReferenceStoresNullSku(): void
    {
        $product = $this->productFixture(12, ['n'], '', ['d']);

        $captured = '';
        $this->db->method('execute')->willReturnCallback(static function (string $sql) use (&$captured): bool {
            $captured = $sql;
            return true;
        });

        $this->writer->upsertFromProduct($product, 1);

        // sku_snapshot must serialize as NULL (not as '')
        self::assertMatchesRegularExpression(
            "/'n', NULL,/",
            $captured,
            'Expected NULL for empty reference between display_name and description columns.'
        );
    }

    public function testEmptyDescriptionShortStoresNullDescription(): void
    {
        $product = $this->productFixture(13, ['n'], 'r', ['']);

        $captured = '';
        $this->db->method('execute')->willReturnCallback(static function (string $sql) use (&$captured): bool {
            $captured = $sql;
            return true;
        });

        $this->writer->upsertFromProduct($product, 1);

        self::assertMatchesRegularExpression(
            "/'r', NULL,/",
            $captured,
            'Expected NULL for empty description_short column.'
        );
    }

    public function testMissingNameFallsBackToPlaceholderAndLogs(): void
    {
        $product = $this->productFixture(99, [], 'ref', ['d']);

        $messages = [];
        $this->logger->method('addLog')->willReturnCallback(
            static function (string $msg) use (&$messages): void {
                $messages[] = $msg;
            }
        );

        $captured = '';
        $this->db->method('execute')->willReturnCallback(static function (string $sql) use (&$captured): bool {
            $captured = $sql;
            return true;
        });

        $this->writer->upsertFromProduct($product, 1);

        self::assertStringContainsString("'product-99'", $captured);
        self::assertStringContainsString(
            'no usable name in any language',
            implode("\n", $messages)
        );
    }

    /**
     * @param array<int|string, string>|string $name
     * @param array<int|string, string>|string $descriptionShort
     */
    private function productFixture(
        int $id,
        array|string $name,
        string $reference,
        array|string $descriptionShort
    ): Product {
        $product = new Product();
        $product->id = $id;
        $product->name = $this->normalizeLangArray($name);
        $product->reference = $reference;
        $product->description_short = $this->normalizeLangArray($descriptionShort);
        return $product;
    }

    /**
     * Accepts either a string, a list (defaults keyed by language id starting at 1),
     * or an explicit lang_id-keyed map.
     *
     * @param array<int|string, string>|string $value
     * @return array<int, string>|string
     */
    private function normalizeLangArray(array|string $value): array|string
    {
        if (is_string($value)) {
            return $value;
        }
        // If keys look numeric and contiguous starting at 0, treat as a list:
        // [0=>'a',1=>'b'] -> [1=>'a',2=>'b'] to mimic PS lang-id keying.
        if (array_is_list($value)) {
            $out = [];
            foreach ($value as $idx => $v) {
                $out[$idx + 1] = (string) $v;
            }
            return $out;
        }
        $out = [];
        foreach ($value as $k => $v) {
            $out[(int) $k] = (string) $v;
        }
        return $out;
    }
}
