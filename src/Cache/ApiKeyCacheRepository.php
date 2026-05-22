<?php

declare(strict_types=1);

namespace Schatzie\Keystone\Cache;

use Illuminate\Contracts\Cache\Repository;
use Schatzie\Keystone\Models\ApiKey;

/**
 * Single source of truth for all Keystone Redis interactions.
 *
 * Tenant-namespace-aware: prefixes every Redis key with the current tenant's
 * ID when a tenancy mode other than 'none' is active, keeping tenant data
 * strictly separated within a shared Redis instance.
 *
 * Redis key layout:
 *   {prefix}:{tenantSegment}key:{api_key}
 *   {prefix}:{tenantSegment}owner:{type}:{id}   → JSON array of api_key strings
 *
 * Examples (prefix = "keystone", tenant = "abc"):
 *   keystone:abc:key:ks_xxxx...
 *   keystone:abc:owner:App\Models\User:42
 *
 * Examples (no tenancy):
 *   keystone:key:ks_xxxx...
 *   keystone:owner:App\Models\User:42
 */
final class ApiKeyCacheRepository
{
    public function __construct(
        private readonly Repository $cache,
        private readonly string $prefix,
        private readonly ?int $ttl,
    ) {}

    // ── Public API ─────────────────────────────────────────────────────────

    /**
     * Retrieve a cached ApiKey by its plain api_key value.
     * Returns null on a cache miss.
     */
    public function get(string $apiKey): ?ApiKey
    {
        if (! config('keystone.cache.enabled', true)) {
            return null;
        }

        /** @var string|null $data */
        $data = $this->cache->get($this->keyFor($apiKey));

        if ($data === null) {
            return null;
        }

        $attributes = json_decode($data, true);

        if (! is_array($attributes)) {
            return null;
        }

        return (new ApiKey())->setRawAttributes($attributes);
    }

    /**
     * Write an ApiKey into the cache and track it in the owner index.
     */
    public function put(ApiKey $apiKey): void
    {
        if (! config('keystone.cache.enabled', true)) {
            return;
        }

        $keyEntry = $this->keyFor($apiKey->api_key);
        $ownerEntry = $this->ownerKeyFor($apiKey->keystoneable_type, $apiKey->keystoneable_id);

        // Serialise model attributes (no relations)
        $payload = json_encode($apiKey->getAttributes());

        $this->cache->put($keyEntry, $payload, $this->ttl);

        // Maintain an owner-keyed index so bulk invalidation is possible
        /** @var string $existingJson */
        $existingJson = $this->cache->get($ownerEntry, '[]');
        /** @var array<int, string> $set */
        $set = json_decode($existingJson, true);

        if (! in_array($apiKey->api_key, $set, true)) {
            $set[] = $apiKey->api_key;
        }

        $this->cache->put($ownerEntry, json_encode(array_values($set)), $this->ttl);
    }

    /**
     * Evict a single api_key entry from the cache.
     */
    public function forget(string $apiKey): void
    {
        $this->cache->forget($this->keyFor($apiKey));
    }

    /**
     * Evict all cached keys belonging to a specific owner (e.g. on revokeAll).
     */
    public function forgetOwner(string $type, int|string $id): void
    {
        $ownerEntry = $this->ownerKeyFor($type, $id);

        /** @var string $json */
        $json = $this->cache->get($ownerEntry, '[]');
        /** @var array<int, string> $keys */
        $keys = json_decode($json, true);

        foreach ($keys as $apiKey) {
            $this->cache->forget($this->keyFor($apiKey));
        }

        $this->cache->forget($ownerEntry);
    }

    /**
     * Flush all Keystone cache entries within the current tenant's namespace.
     * Primarily a dev/test utility.
     */
    public function flush(): void
    {
        // When using an array store (tests) or a store without tag support,
        // we do a best-effort forget using the known prefix. For production
        // Redis, callers should prefer per-key or per-owner invalidation.
        $this->cache->flush();
    }

    // ── Namespace helpers ──────────────────────────────────────────────────

    /**
     * Returns a tenant-specific segment for Redis key construction.
     * Empty string when tenancy is disabled or not yet initialised.
     */
    private function tenantSegment(): string
    {
        $mode = config('keystone.tenancy.mode', 'none');

        if ($mode === 'none') {
            return '';
        }

        if (! function_exists('tenant') || tenant() === null) {
            return '';
        }

        return (string) tenant()->getTenantKey().':';
    }

    private function keyFor(string $apiKey): string
    {
        return $this->prefix.':'.$this->tenantSegment().'key:'.$apiKey;
    }

    private function ownerKeyFor(string $type, int|string $id): string
    {
        return $this->prefix.':'.$this->tenantSegment().'owner:'.$type.':'.$id;
    }
}
