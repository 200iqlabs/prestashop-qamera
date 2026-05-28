<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Cache;

use QameraAi\Module\Api\Dto\AiModel;
use QameraAi\Module\Api\Dto\AspectRatio;
use QameraAi\Module\Api\Dto\MannequinModel;
use QameraAi\Module\Api\Dto\Preset;
use QameraAi\Module\Api\Dto\Pricing;
use QameraAi\Module\Api\Dto\Scenery;
use QameraAi\Module\Api\QameraApiClient;

/**
 * TTL-cached proxy over the six reference-data endpoints. BO controllers
 * depend on this seam (NOT on {@see QameraApiClient} directly) so multiple
 * loads of the generate form during one operator session do not
 * stampede upstream.
 *
 * Per-endpoint TTLs come from the `qamera-api-client` delta spec; all are
 * 300s except `/aspect-ratios` which is 3600s (effectively a static
 * enum). Cache entries are keyed by `sha256(apiKey)[0:16]` so an API-key
 * rotation cannot serve stale data scoped to the previous account.
 *
 * The decorator is intentionally NOT used by tests of `QameraApiClient`
 * itself — those tests hit the underlying client via `MockHandler`
 * directly. Tests of this class inject a stub client + a temp cache dir.
 */
/**
 * NOT `final` so test stubs can subclass and override individual reference
 * methods without touching the cache backend (see CostCalculatorTest).
 */
class CachedReferenceClient
{
    public const TTL_AI_MODELS = 300;
    public const TTL_SCENERIES = 300;
    public const TTL_MANNEQUIN_MODELS = 300;
    public const TTL_PRESETS = 300;
    public const TTL_ASPECT_RATIOS = 3600;
    public const TTL_PRICING = 300;

    public function __construct(
        private readonly QameraApiClient $client,
        private readonly ReferenceCache $cache,
        private readonly string $apiKey,
    ) {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('apiKey must not be empty');
        }
    }

    /**
     * @return AiModel[]
     */
    public function listAiModels(): array
    {
        return $this->remember('/ai-models', self::TTL_AI_MODELS, fn () => $this->client->listAiModels());
    }

    /**
     * @return Scenery[]
     */
    public function listSceneries(): array
    {
        return $this->remember('/sceneries', self::TTL_SCENERIES, fn () => $this->client->listSceneries());
    }

    /**
     * @return MannequinModel[]
     */
    public function listMannequinModels(): array
    {
        return $this->remember('/models', self::TTL_MANNEQUIN_MODELS, fn () => $this->client->listMannequinModels());
    }

    /**
     * @return Preset[]
     */
    public function listPresets(): array
    {
        return $this->remember('/presets', self::TTL_PRESETS, fn () => $this->client->listPresets());
    }

    /**
     * @return AspectRatio[]
     */
    public function listAspectRatios(): array
    {
        return $this->remember('/aspect-ratios', self::TTL_ASPECT_RATIOS, fn () => $this->client->listAspectRatios());
    }

    public function getPricing(): Pricing
    {
        return $this->remember('/pricing', self::TTL_PRICING, fn () => $this->client->getPricing());
    }

    /**
     * @template T
     *
     * @param callable(): T $producer
     *
     * @return T
     */
    private function remember(string $endpoint, int $ttl, callable $producer): mixed
    {
        $key = ReferenceCache::key($endpoint, $this->apiKey);
        $cached = $this->cache->get($key, $ttl);
        if ($cached !== null) {
            /** @var T */
            return $cached;
        }

        $fresh = $producer();
        $this->cache->set($key, $fresh);
        return $fresh;
    }
}
