<?php

declare(strict_types=1);

namespace QameraAi\Module\Sync;

/**
 * Per-request in-memory dedup cache for `(idProduct, idImage)` pairs
 * already processed by `ProductImageSyncService`. PS may fire
 * `actionWatermark` more than once per image during bulk regenerate
 * flows; this cache short-circuits the duplicate invocations.
 *
 * Lives only for the current request — no persistence, no eviction.
 * Extracted from `ProductImageSyncService` so integration tests can
 * inject a fresh instance per test (see `add-ps-kernel-integration-tests`).
 */
final class InMemoryDedupCache
{
    /** @var array<string, true> */
    private array $store = [];

    /**
     * Atomically check-and-mark. Returns true if the key was already
     * present (caller should skip), false if this is the first sighting
     * (caller should proceed).
     */
    public function seen(string $key): bool
    {
        if (isset($this->store[$key])) {
            return true;
        }
        $this->store[$key] = true;
        return false;
    }
}
