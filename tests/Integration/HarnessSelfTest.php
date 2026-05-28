<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Integration;

use Configuration;
use Context;
use Db;
use Module;
use PrestaShop\PrestaShop\Adapter\SymfonyContainer;

/**
 * Sentinel — asserts every piece of state the bootstrap promised in
 * the `integration-test-harness` spec. Runs first by alphabetical
 * ordering of the test file name (H comes before S/P/I). If this
 * class fails, every other integration test is suspect, so fix the
 * bootstrap before chasing downstream failures.
 */
final class HarnessSelfTest extends IntegrationTestCase
{
    public function testKernelBootedAndContainerExposed(): void
    {
        self::assertTrue(defined('_PS_VERSION_'), '_PS_VERSION_ should be defined by config.inc.php');
        self::assertNotNull(SymfonyContainer::getInstance(), 'Symfony container should be exposed by bootstrap');
    }

    public function testDbReturnsRealInstance(): void
    {
        $db = Db::getInstance();
        $rows = $db->executeS('SELECT 1 AS v');
        self::assertIsArray($rows);
        self::assertCount(1, $rows);
        self::assertSame('1', (string) $rows[0]['v']);
    }

    public function testConfigurationOverriddenToInvalidUrl(): void
    {
        $value = Configuration::get('QAMERAAI_API_BASE_URL');
        self::assertSame(
            'http://qamera-test.invalid',
            $value,
            'Bootstrap must redirect API base URL to RFC 2606 .invalid host'
        );
    }

    public function testPsProductImgDirIsDefinedAndExists(): void
    {
        self::assertTrue(defined('_PS_PRODUCT_IMG_DIR_'), '_PS_PRODUCT_IMG_DIR_ must be defined');
        $dir = (string) constant('_PS_PRODUCT_IMG_DIR_');
        self::assertNotSame('', $dir);
        self::assertDirectoryExists($dir);
    }

    public function testShopContextResolvesToShopOne(): void
    {
        $context = Context::getContext();
        self::assertNotNull($context);
        self::assertNotNull($context->shop);
        self::assertSame(1, (int) $context->shop->id);
    }

    public function testModuleInstanceResolvesViaPrestaShop(): void
    {
        $module = Module::getInstanceByName('qameraai');
        self::assertNotFalse($module, 'Module::getInstanceByName(qameraai) should return the live instance');
    }

    public function testSuiteStartedWithNoTestPrefixedRows(): void
    {
        $db = Db::getInstance();
        $count = (int) $db->getValue(
            "SELECT COUNT(*) FROM `" . _DB_PREFIX_ . "product` WHERE reference LIKE 'TEST-%'"
        );
        self::assertSame(
            0,
            $count,
            'Bootstrap sweep should have removed all TEST-prefixed leftovers before tests run'
        );
    }
}
