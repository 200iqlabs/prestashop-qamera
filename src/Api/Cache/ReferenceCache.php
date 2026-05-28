<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Cache;

/**
 * Filesystem-backed TTL cache for reference-data responses keyed by API
 * key hash. Survives request boundaries (unlike a per-request in-memory
 * cache) so operator hopping between BO pages does not re-hit upstream.
 *
 * Deliberate simplification vs. spec D4: skips the `\Cache::getInstance()`
 * indirection. PS's pluggable cache layer is request-bound by default and
 * its slot-based key namespace adds test-environment surface area for no
 * runtime gain — the spec called it out as a fallback path. Filesystem
 * alone gives us survives-the-request semantics with deterministic test
 * setup/teardown.
 *
 * Key shape: `qameraai:ref:<endpoint>:<sha256(api_key)[0:16]>`. Stored on
 * disk as `<sha256(key)>.cache` under `cacheDir` to avoid path-injection
 * risk from the endpoint string and to keep filenames safe across OSes.
 *
 * Stored value: `['stored_at' => int, 'payload' => string]` where
 * `payload` is `serialize($value)`. Entries past `stored_at + ttl` are
 * treated as misses and lazily overwritten on the next `set()`.
 */
final class ReferenceCache
{
    /**
     * Strict allowlist of DTO classes that may be hydrated from the cache.
     * Any other class encountered during unserialize() becomes
     * `__PHP_Incomplete_Class`, which we reject as a corrupted entry. This
     * shuts down PHP object-injection vectors if a cache file is tampered
     * with on disk: an attacker cannot smuggle in a class with a malicious
     * `__destruct`/`__wakeup` because it is not on this list.
     *
     * @var list<class-string>
     */
    private const ALLOWED_CLASSES = [
        \QameraAi\Module\Api\Dto\AiModel::class,
        \QameraAi\Module\Api\Dto\AspectRatio::class,
        \QameraAi\Module\Api\Dto\MannequinModel::class,
        \QameraAi\Module\Api\Dto\Preset::class,
        \QameraAi\Module\Api\Dto\Pricing::class,
        \QameraAi\Module\Api\Dto\PricingEntry::class,
        \QameraAi\Module\Api\Dto\Scenery::class,
    ];

    public function __construct(private readonly string $cacheDir)
    {
        if ($this->cacheDir === '') {
            throw new \InvalidArgumentException('cacheDir must not be empty');
        }
    }

    /**
     * @return mixed|null  Cached value, or null on miss / expiry / read error.
     */
    public function get(string $logicalKey, int $ttlSeconds): mixed
    {
        $path = $this->path($logicalKey);
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        // Envelope itself contains no objects — refuse any class to keep
        // the outer unserialize() purely scalar.
        $envelope = @unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($envelope) || !isset($envelope['stored_at'], $envelope['payload'])) {
            return null;
        }

        $storedAt = (int) $envelope['stored_at'];
        if (time() - $storedAt > $ttlSeconds) {
            return null;
        }

        $payload = $envelope['payload'];
        if (!is_string($payload)) {
            return null;
        }

        $value = @unserialize($payload, ['allowed_classes' => self::ALLOWED_CLASSES]);
        if ($value === false && $payload !== serialize(false)) {
            return null;
        }
        if (!$this->isHydrationSafe($value)) {
            return null;
        }
        return $value;
    }

    public function set(string $logicalKey, mixed $value): void
    {
        if (!is_dir($this->cacheDir) && !@mkdir($this->cacheDir, 0775, true) && !is_dir($this->cacheDir)) {
            return; // best-effort: silent miss is preferred over a hard crash
        }

        $envelope = serialize([
            'stored_at' => time(),
            'payload' => serialize($value),
        ]);

        // Atomic write: stage to a temp file in the same dir, then rename.
        // `rename()` on the same volume is atomic on POSIX; on Windows it
        // is atomic when the destination does not exist — when it does,
        // we tolerate the small TOCTOU window because the worst case is
        // one extra upstream call.
        $path = $this->path($logicalKey);
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $envelope) === false) {
            return;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
        }
    }

    public function forget(string $logicalKey): void
    {
        $path = $this->path($logicalKey);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Build the cache-key string callers use; exposed publicly so the
     * decorator can compose `endpoint + api_key_hash` consistently.
     */
    public static function key(string $endpoint, string $apiKey): string
    {
        $apiKeyHash = substr(hash('sha256', $apiKey), 0, 16);
        return sprintf('qameraai:ref:%s:%s', $endpoint, $apiKeyHash);
    }

    /**
     * Recursively confirm that no `__PHP_Incomplete_Class` instance slipped
     * through the allowlist (which would indicate a disallowed class name
     * was present in the serialized payload).
     */
    private function isHydrationSafe(mixed $value): bool
    {
        if ($value instanceof \__PHP_Incomplete_Class) {
            return false;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (!$this->isHydrationSafe($item)) {
                    return false;
                }
            }
        }
        return true;
    }

    private function path(string $logicalKey): string
    {
        $filename = hash('sha256', $logicalKey) . '.cache';
        return rtrim($this->cacheDir, "/\\") . DIRECTORY_SEPARATOR . $filename;
    }
}
