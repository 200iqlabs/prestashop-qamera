<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Api\Cache;

use PHPUnit\Framework\TestCase;
use QameraAi\Module\Api\Cache\CachedReferenceClient;
use QameraAi\Module\Api\Cache\ReferenceCache;
use QameraAi\Module\Api\Dto\AiModel;
use QameraAi\Module\Api\Dto\Pricing;
use QameraAi\Module\Api\Dto\PricingEntry;
use QameraAi\Module\Api\QameraApiClient;

final class CachedReferenceClientTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'qameraai-cached-ref-client-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            foreach ((array) scandir($this->dir) as $f) {
                if (in_array($f, ['.', '..'], true)) {
                    continue;
                }
                @unlink($this->dir . DIRECTORY_SEPARATOR . $f);
            }
            @rmdir($this->dir);
        }
        parent::tearDown();
    }

    public function testTwoCallsWithinTtlHitTheCacheAndCallUpstreamOnce(): void
    {
        $calls = 0;
        $stubClient = $this->makeStubClient(
            listAiModels: function () use (&$calls): array {
                $calls++;
                return [
                    new AiModel(
                        id: 'openai/gpt-image-1',
                        provider: 'openai',
                        model: 'gpt-image-1',
                        outputType: 'image',
                        supportedAspectRatios: ['1:1'],
                        baseCreditCost: 5,
                    ),
                ];
            },
        );
        $cache = new ReferenceCache($this->dir);
        $decorator = new CachedReferenceClient($stubClient, $cache, 'test-api-key');

        $first = $decorator->listAiModels();
        $second = $decorator->listAiModels();

        self::assertSame(1, $calls);
        self::assertCount(1, $first);
        self::assertEquals($first, $second);
    }

    public function testDifferentApiKeysDoNotShareCacheEntries(): void
    {
        $calls = 0;
        $stubClient = $this->makeStubClient(
            getPricing: function () use (&$calls): Pricing {
                $calls++;
                return new Pricing(
                    pricing: [new PricingEntry('packshot', 'openai', 'gpt-image-1', $calls * 10)],
                    currency: 'USD',
                );
            },
        );
        $cache = new ReferenceCache($this->dir);
        $decoratorA = new CachedReferenceClient($stubClient, $cache, 'key-A');
        $decoratorB = new CachedReferenceClient($stubClient, $cache, 'key-B');

        $a = $decoratorA->getPricing();
        $b = $decoratorB->getPricing();

        // Two distinct upstream calls because the cache is keyed by the
        // SHA-256 of the API key (sharing across keys would leak prior
        // account's reference data).
        self::assertSame(2, $calls);
        self::assertNotSame($a->getEntries()[0]->creditCost, $b->getEntries()[0]->creditCost);
    }

    public function testEmptyApiKeyIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CachedReferenceClient($this->makeStubClient(), new ReferenceCache($this->dir), '');
    }

    /**
     * Build a stub QameraApiClient by extending the real class with
     * trivial overrides for the per-test method(s) we exercise. The
     * parent ctor needs concrete deps; we feed it the same ones the
     * production unit tests use (header builder etc.).
     *
     * @param callable(): array<AiModel>|null $listAiModels
     * @param callable(): Pricing|null        $getPricing
     */
    private function makeStubClient(
        ?callable $listAiModels = null,
        ?callable $getPricing = null,
    ): QameraApiClient {
        return new class ($listAiModels, $getPricing) extends QameraApiClient {
            /** @var callable(): array<AiModel>|null */
            private $listAiModelsCb;
            /** @var callable(): Pricing|null */
            private $getPricingCb;

            public function __construct(?callable $listAiModels, ?callable $getPricing)
            {
                $this->listAiModelsCb = $listAiModels;
                $this->getPricingCb = $getPricing;
                // Do NOT call parent ctor — the stub never makes HTTP
                // calls. Sidestepping the parent avoids constructing a
                // Guzzle stack just for unit tests of the cache layer.
            }

            public function listAiModels(): array
            {
                return ($this->listAiModelsCb)();
            }

            public function getPricing(): Pricing
            {
                return ($this->getPricingCb)();
            }
        };
    }
}
