<?php

declare(strict_types=1);

namespace QameraAi\Module\Gallery;

use QameraAi\Module\Api\QameraApiClient;
use Throwable;

/**
 * Verifies the installation holds the `plugin.catalog:write` scope before
 * the gallery picker exposes ingest actions (design D10). The `/me` lookup
 * is memoised for the lifetime of the instance so a single request pays at
 * most one round-trip regardless of how many images are ingested.
 *
 * Any failure to determine scopes (network, auth) is treated as "no write
 * scope" — the UI blocks ingest rather than letting a doomed call through.
 */
class WriteScopeChecker
{
    public const WRITE_SCOPE = 'plugin.catalog:write';

    private ?bool $cached = null;

    public function __construct(private readonly QameraApiClient $apiClient)
    {
    }

    public function hasWriteScope(): bool
    {
        if ($this->cached !== null) {
            return $this->cached;
        }

        try {
            $me = $this->apiClient->me();
            $this->cached = in_array(self::WRITE_SCOPE, $me->installation->scopes, true);
        } catch (Throwable $e) {
            $this->cached = false;
        }

        return $this->cached;
    }
}
