<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Integration;

use Configuration;
use Db;
use PHPUnit\Framework\TestCase;
use PrestaShop\PrestaShop\Adapter\SymfonyContainer;
use RuntimeException;

use function QameraAi\Module\Tests\Integration\cleanupTestFixturesByMarker;

/**
 * Base class for every `tests/Integration/` test. Provides the per-
 * test marker, teardown of all marker-tagged fixtures, and helpers
 * for the two most common mutations a test makes — rebinding a
 * container service (e.g. swap `QameraApiClient` for a Guzzle
 * `MockHandler` wrapper) and overriding a `ps_configuration` value.
 * Each mutation is restored automatically in `tearDown`, even if the
 * test threw.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected string $marker = '';

    /** @var array<int, array{0:string, 1:?object, 2:bool}> */
    private array $serviceRebinds = [];

    /** @var array<string, string|false> */
    private array $configurationOverrides = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('QAMERAAI_INTEGRATION_BOOTSTRAPPED')) {
            throw new RuntimeException(
                'IntegrationTestCase requires the integration bootstrap '
                . '(phpunit.integration.xml.dist). Run with -c phpunit.integration.xml.dist.'
            );
        }
        $this->marker = bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        // Restore Configuration overrides first so any cleanup that
        // reads Configuration sees production values.
        foreach ($this->configurationOverrides as $key => $original) {
            if ($original === false) {
                Configuration::deleteByName($key);
            } else {
                Configuration::updateValue($key, $original);
            }
        }
        $this->configurationOverrides = [];

        // Restore service rebinds.
        $container = SymfonyContainer::getInstance();
        if ($container !== null) {
            foreach ($this->serviceRebinds as [$id, $original, $hadOriginal]) {
                if ($hadOriginal) {
                    $container->set($id, $original);
                } else {
                    $container->set($id, null);
                }
            }
        }
        $this->serviceRebinds = [];

        if ($this->marker !== '') {
            cleanupTestFixturesByMarker(Db::getInstance(), $this->marker);
        }

        parent::tearDown();
    }

    /**
     * Replaces the container's binding for `$id` with `$instance` and
     * remembers the original so `tearDown` can restore it. Safe to
     * call multiple times for the same `$id` — the first original
     * wins.
     */
    protected function rebindContainerService(string $id, object $instance): void
    {
        $container = SymfonyContainer::getInstance();
        if ($container === null) {
            throw new RuntimeException(
                'rebindContainerService: SymfonyContainer not available — bootstrap did not run?'
            );
        }
        if (!$this->serviceRebindRecorded($id)) {
            $original = $container->has($id) ? $container->get($id) : null;
            $this->serviceRebinds[] = [$id, $original, $container->has($id)];
        }
        $container->set($id, $instance);
    }

    /**
     * Writes `$value` to `Configuration` and remembers the original
     * (or its absence) so `tearDown` can restore it. Idempotent — the
     * first original wins.
     */
    protected function setConfigurationOverride(string $key, string $value): void
    {
        if (!array_key_exists($key, $this->configurationOverrides)) {
            $original = Configuration::get($key);
            $this->configurationOverrides[$key] = is_string($original) ? $original : false;
        }
        Configuration::updateValue($key, $value);
    }

    private function serviceRebindRecorded(string $id): bool
    {
        foreach ($this->serviceRebinds as [$existingId]) {
            if ($existingId === $id) {
                return true;
            }
        }
        return false;
    }
}
