<?php

declare(strict_types=1);

// Integration test harness bootstrap.
//
// Boots a real PrestaShop 9 kernel inside the dev container so that
// tests under `tests/Integration/` exercise production code paths
// against real `Db`, real `_PS_PRODUCT_IMG_DIR_`, real
// `Module::getInstanceByName('qameraai')` autoload composition.
//
// Spec: `integration-test-harness` — boot once per process, idempotent,
// Configuration override to invalid host, suite-wide `TEST-` sweep.

if (defined('QAMERAAI_INTEGRATION_BOOTSTRAPPED')) {
    return;
}
define('QAMERAAI_INTEGRATION_BOOTSTRAPPED', true);

require_once __DIR__ . '/../../vendor/autoload.php';

$psRoot = getenv('QAMERAAI_PS_ROOT');
if (!is_string($psRoot) || $psRoot === '') {
    $psRoot = '/var/www/html';
}

$configPath = $psRoot . '/config/config.inc.php';
if (!is_file($configPath)) {
    fwrite(
        STDERR,
        sprintf(
            "[qameraai integration bootstrap] PrestaShop config not found at %s.\n"
            . "Integration tests must run inside the dev container "
            . "(qameraai-prestashop/docker-compose.yml). Override the PS root with "
            . "QAMERAAI_PS_ROOT if your container layout differs.\n",
            $configPath
        )
    );
    exit(1);
}

require_once $configPath;

if (!class_exists(\AdminKernel::class)) {
    fwrite(STDERR, "[qameraai integration bootstrap] AdminKernel class not available after config load.\n");
    exit(1);
}

$kernel = new \AdminKernel('dev', true);
$kernel->boot();
$container = $kernel->getContainer();

if ($container === null) {
    fwrite(STDERR, "[qameraai integration bootstrap] Kernel container is null after boot().\n");
    exit(1);
}

// Expose the booted container so PS legacy code paths that resolve via
// `SymfonyContainer::getInstance()` see the same instance the kernel
// holds. Older PS releases keep `setInstance` private — fall back to
// reflection to stay portable across the 8.0 → 9.x range.
$symfonyContainerClass = \PrestaShop\PrestaShop\Adapter\SymfonyContainer::class;
if (class_exists($symfonyContainerClass)) {
    if (method_exists($symfonyContainerClass, 'setInstance')) {
        $symfonyContainerClass::setInstance($container);
    } else {
        $ref = new \ReflectionClass($symfonyContainerClass);
        if ($ref->hasProperty('instance')) {
            $prop = $ref->getProperty('instance');
            $prop->setAccessible(true);
            $prop->setValue(null, $container);
        }
    }
}

if (class_exists(\Shop::class)) {
    \Shop::setContext(\Shop::CONTEXT_SHOP, 1);
}
if (class_exists(\Context::class)) {
    $context = \Context::getContext();
    if ($context !== null && class_exists(\Shop::class)) {
        $context->shop = new \Shop(1);
    }
}

require_once __DIR__ . '/cleanup.php';

$db = \Db::getInstance();

// Spec §3: redirect API base URL to RFC 2606 `.invalid` so any test
// that forgets to rebind the API client and tries real HTTP fails with
// DNS resolution error — never reaches qamera.ai with whatever
// credentials the dev container's `ps_configuration` carries.
$originalBaseUrl = \Configuration::get('QAMERAAI_API_BASE_URL');
\Configuration::updateValue('QAMERAAI_API_BASE_URL', 'http://qamera-test.invalid');

// Spec §4: suite-wide sweep of TEST- prefixed fixtures from prior runs.
\QameraAi\Module\Tests\Integration\cleanupTestFixtures($db);

register_shutdown_function(static function () use ($originalBaseUrl): void {
    if (is_string($originalBaseUrl) && $originalBaseUrl !== '') {
        \Configuration::updateValue('QAMERAAI_API_BASE_URL', $originalBaseUrl);
    } else {
        \Configuration::deleteByName('QAMERAAI_API_BASE_URL');
    }
});

unset($psRoot, $configPath, $kernel, $container, $symfonyContainerClass, $ref, $prop, $db, $originalBaseUrl);
