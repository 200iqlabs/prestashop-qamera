<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Cache;

use Configuration;
use QameraAi\Module\Api\Factory\MissingConfigurationException;
use QameraAi\Module\Api\QameraApiClient;

/**
 * Builds a {@see CachedReferenceClient} keyed by the currently-configured
 * API key. Exists so the cache decorator can be auto-wired by Symfony
 * without resolving Configuration values at container compile time —
 * that read fires before `Configuration::init()` during module install
 * and corrupts the bootstrap.
 *
 * Each invocation reads the live Configuration value; a key rotation
 * picks up on the very next request (entries from the previous key TTL
 * out naturally — see `qamera-api-client` delta spec).
 */
final class CachedReferenceClientFactory
{
    public function __construct(
        private readonly QameraApiClient $client,
        private readonly ReferenceCache $cache,
    ) {
    }

    /**
     * @throws MissingConfigurationException when QAMERAAI_API_KEY is empty
     */
    public function create(): CachedReferenceClient
    {
        $apiKey = trim((string) Configuration::get('QAMERAAI_API_KEY'));
        if ($apiKey === '') {
            throw new MissingConfigurationException('QAMERAAI_API_KEY is not configured');
        }

        return new CachedReferenceClient($this->client, $this->cache, $apiKey);
    }
}
